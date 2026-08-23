<?php
/**
 * END-TO-END INTEGRATION TEST — DESTRUCTIVE / WRITES TO LIVE DATABASE
 * ================================================================
 * WARNING: This script performs REAL writes against the connected MySQL
 * database and issues REAL HTTP requests to the local API. It creates
 * bookings, payment_transactions, charging_sessions, and activity_logs
 * rows, and resets/mutates state. It is intended for LOCAL development
 * use only.
 *
 * DO NOT:
 *   - deploy this file anywhere it can be reached over HTTP
 *   - run it against a production or non-disposable database
 *   - commit the cookie files it generates (dc.txt / oc.txt)
 *
 * It uses PHP cURL + PDO and expects:
 *   - Apache/MySQL running locally with the app at http://localhost/EE
 *   - seed accounts driver1@example.com / owner1@example.com
 *     with password Test@123 (see database/schema.sql seed data)
 */
error_reporting(E_ALL); ini_set('display_errors', 1);
$BASE='http://localhost/EE';
$db=new PDO('mysql:host=localhost;dbname=ev_charging_db;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function api($m,$u,$c,$p=null){$ch=curl_init($u);$o=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$m,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_COOKIEJAR=>$c,CURLOPT_COOKIEFILE=>$c,CURLOPT_USERAGENT=>'IntegrationTest/1.0'];if($p!==null)$o[CURLOPT_POSTFIELDS]=json_encode($p);curl_setopt_array($ch,$o);$r=curl_exec($ch);curl_close($ch);return json_decode($r,true);}
function q($db,$s,$p=[]){$st=$db->prepare($s);$st->execute($p);return $st->fetchAll();}
function rep($s,$p,$d){echo($p?"PASS":"FAIL")." | $s | $d\n";}
$dc=__DIR__.'/dc.txt';$oc=__DIR__.'/oc.txt';@unlink($dc);@unlink($oc);
$l=api('POST',"$BASE/api/auth/login.php",$dc,['email'=>'driver1@example.com','password'=>'Test@123','user_type'=>'driver']);
rep('Login driver',$l['status']==='success',json_encode($l));
$lo=api('POST',"$BASE/api/auth/login.php",$oc,['email'=>'owner1@example.com','password'=>'Test@123','user_type'=>'owner']);
rep('Login owner',$lo['status']==='success',json_encode($lo));
// STEP 1
$i=api('POST',"$BASE/api/bookings.php",$dc,['action'=>'initiate_payment','charger_id'=>1]);
rep('1. initiate_payment',$i['status']==='success'&&$i['data']['estimated_cost']==50,json_encode($i));
$bid=$i['data']['booking_id']??null;
$b=q($db,"SELECT status,estimated_total_cost,base_fee,car_current_battery_percent FROM bookings WHERE id=?",[$bid]);
rep('2. pending_payment',$b[0]['status']==='pending_payment'&&$b[0]['estimated_total_cost']==50&&$b[0]['base_fee']==50&&$b[0]['car_current_battery_percent']===null,json_encode($b[0]));
$c=api('POST',"$BASE/api/bookings.php",$dc,['action'=>'confirm_payment','booking_id'=>$bid]);
rep('3. confirm_payment',$c['status']==='success',json_encode($c));
$b=q($db,"SELECT status,payment_status FROM bookings WHERE id=?",[$bid]);
$pt=q($db,"SELECT amount FROM payment_transactions WHERE booking_id=?",[$bid]);
rep('4. booked+1txn',$b[0]['status']==='booked'&&$b[0]['payment_status']==='completed'&&count($pt)===1&&$pt[0]['amount']==50,'b='.json_encode($b[0]).' t='.json_encode($pt));
// STEP 2
$bat=40;
$ic=api('POST',"$BASE/api/bookings.php",$dc,['action'=>'initiate_charging_payment','booking_id'=>$bid,'battery_percent'=>$bat]);
rep('5. initiate_charging',$ic['status']==='success',json_encode($ic));
$d=$ic['data']??[];
rep('5b. math',$d['kwh_needed']==45&&$d['charge_time_minutes']==54&&$d['charging_cost']==450,'exp kwh=45,t=54,c=450 got '.json_encode($d));
$b=q($db,"SELECT status FROM bookings WHERE id=?",[$bid]);
rep('6. still booked',$b[0]['status']==='booked','status='.$b[0]['status']);
$cc=api('POST',"$BASE/api/bookings.php",$dc,['action'=>'confirm_charging_payment','booking_id'=>$bid,'battery_percent'=>$bat]);
rep('7. confirm_charging',$cc['status']==='success',json_encode($cc));
$b=q($db,"SELECT status,car_current_battery_percent,estimated_total_cost,buffer_ends_at,session_ends_at FROM bookings WHERE id=?",[$bid]);
rep('8. charging',$b[0]['status']==='charging'&&$b[0]['car_current_battery_percent']==40&&$b[0]['buffer_ends_at']===null&&$b[0]['session_ends_at']!==null,json_encode($b[0]));
$ch=q($db,"SELECT status FROM chargers WHERE id=1");
rep('9. charger charging',$ch[0]['status']==='charging','status='.$ch[0]['status']);
$cs=q($db,"SELECT battery_start_percent,per_kwh_rate,payment_status FROM charging_sessions WHERE booking_id=?",[$bid]);
rep('10. session exists',count($cs)===1&&$cs[0]['battery_start_percent']==40&&$cs[0]['payment_status']==='completed',json_encode($cs[0]));
$pt=q($db,"SELECT amount FROM payment_transactions WHERE booking_id=? ORDER BY id",[$bid]);
rep('11. two txns',count($pt)===2&&$pt[0]['amount']==50&&$pt[1]['amount']==450,json_encode($pt));
$al=q($db,"SELECT action,details FROM activity_logs WHERE user_id=1 AND action='session_started' AND resource_id=?",[$bid]);
rep('12. activity_logs',count($al)===1&&strpos($al[0]['details'],'NPR 500.00')!==false,json_encode($al[0]));
// STEP 3
$comp=api('PUT',"$BASE/api/bookings.php?id=$bid",$oc,['action'=>'complete_session']);
rep('13. complete_session',$comp['status']==='success',json_encode($comp));
$b=q($db,"SELECT status,payment_amount FROM bookings WHERE id=?",[$bid]);
rep('14. completed',$b[0]['status']==='completed',json_encode($b[0]));
$ch=q($db,"SELECT status FROM chargers WHERE id=1");
rep('15. charger available',$ch[0]['status']==='available','status='.$ch[0]['status']);
// STEP 4
$not=q($db,"SELECT action,details FROM activity_logs WHERE user_id=1 ORDER BY created_at DESC LIMIT 5");
rep('16. notifications query',count($not)>0&&$not[0]['action']==='session_started',json_encode($not));
$stuck=q($db,"SELECT id,status FROM bookings WHERE status='charging'");
$stuckc=q($db,"SELECT id,status FROM chargers WHERE status='charging'");
rep('17. no stuck rows',count($stuck)===0&&count($stuckc)===0,'bookings='.json_encode($stuck).' chargers='.json_encode($stuckc));
// STEP 5: STOP CHARGING (driver-initiated early stop) — needs its own fresh booking
$i2=api('POST',"$BASE/api/bookings.php",$dc,['action'=>'initiate_payment','charger_id'=>1]);
$bid2=$i2['data']['booking_id']??null;
api('POST',"$BASE/api/bookings.php",$dc,['action'=>'confirm_payment','booking_id'=>$bid2]);
api('POST',"$BASE/api/bookings.php",$dc,['action'=>'confirm_charging_payment','booking_id'=>$bid2,'battery_percent'=>40]);
$stop=api('POST',"$BASE/api/bookings.php",$dc,['action'=>'stop_session','booking_id'=>$bid2]);
rep('18. stop_session',$stop['status']==='success',json_encode($stop));
$b=q($db,"SELECT status,payment_status,payment_amount FROM bookings WHERE id=?",[$bid2]);
rep('19. stopped status',$b[0]['status']==='stopped'&&$b[0]['payment_status']==='completed'&&$b[0]['payment_amount']==500,json_encode($b[0]));
$ch=q($db,"SELECT status FROM chargers WHERE id=1");
rep('20. charger released',$ch[0]['status']==='available','status='.$ch[0]['status']);
$al=q($db,"SELECT action,details FROM activity_logs WHERE user_id=1 AND action='session_stopped' AND resource_id=?",[$bid2]);
rep('21. session_stopped log',count($al)===1&&strpos($al[0]['details'],'NOT refunded')!==false,json_encode($al[0]));
// STEP 6: NOTIFICATION BELL — seeded-delta assertions, owner scope-leak regression, isolation
$drvId = q($db, "SELECT id FROM users WHERE email='driver1@example.com'")[0]['id'];
// 22: seed exactly 3 known driver notifications, assert unread rises by EXACTLY 3
$uBefore = (int)(api('GET', "$BASE/api/notifications.php", $dc)['data']['unread_count'] ?? -1);
// Seeds must reference a driver-owned booking (driver scope uses EXISTS on bookings).
// Self-contained: reuse the newest driver booking, or create + cleanup a throwaway one.
$sb = q($db, "SELECT id FROM bookings WHERE user_id=? ORDER BY id DESC LIMIT 1", [$drvId]);
$seedBid = $sb ? (int)$sb[0]['id'] : 0;
$seedBookingCreated = false;
if (!$seedBid) {
    $db->exec("INSERT INTO bookings (user_id, charger_id, status) VALUES ($drvId, 1, 'completed')");
    $seedBid = (int)$db->lastInsertId();
    $seedBookingCreated = true;
}
$db->exec("INSERT INTO activity_logs (user_id, action, resource_type, resource_id, details) VALUES
    ($drvId,'test_notif_a','booking',$seedBid,'SEED A'),
    ($drvId,'test_notif_b','booking',$seedBid,'SEED B'),
    ($drvId,'test_notif_c','booking',$seedBid,'SEED C')");
$nb = api('GET', "$BASE/api/notifications.php", $dc);
$uAfter = (int)($nb['data']['unread_count'] ?? -1);
rep('22. bell GET driver (+3 seeded)', $nb['status']==='success' && $uAfter === $uBefore + 3, "before=$uBefore after=$uAfter");
rep('22b. bell newest-first', (($nb['data']['items'][0]['action'] ?? '') === 'test_notif_c'), 'top='.($nb['data']['items'][0]['action'] ?? 'none'));
// cleanup seeds, verify count returns to baseline
$db->exec("DELETE FROM activity_logs WHERE action LIKE 'test_notif_%' AND details LIKE 'SEED %'");
if ($seedBookingCreated) { $db->exec("DELETE FROM bookings WHERE id=$seedBid"); }
$uClean = (int)(api('GET', "$BASE/api/notifications.php", $dc)['data']['unread_count'] ?? -1);
rep('22c. seed cleanup', $uClean === $uBefore, "baseline=$uBefore now=$uClean");
// 23: owner scope-leak regression — synthetic station log with owner_id NULL must be INVISIBLE to owner
$db->exec("INSERT INTO activity_logs (action, resource_type, resource_id, details) VALUES ('station_approved','station',1,'TEST leak probe')");
$no = api('GET', "$BASE/api/notifications.php", $oc);
$leaked = in_array('station_approved', array_column($no['data']['items'] ?? [], 'action'), true);
rep('23. owner scope-leak fixed', $no['status']==='success' && $leaked === false, 'probeSeen='.var_export($leaked, true));
$db->prepare("DELETE FROM activity_logs WHERE action='station_approved' AND details='TEST leak probe'")->execute();
$ownerUnreadBefore = (int)($no['data']['unread_count'] ?? 0);
// 24-25: driver marks all read → driver rows flip, owner rows untouched
$mr = api('POST', "$BASE/api/notifications.php", $dc, ['action'=>'mark_all_read']);
rep('24. mark_all_read driver', $mr['status']==='success' && ($mr['data']['unread_count']??-1)===0, json_encode($mr));
// 24b: on-open behavior fires this repeatedly — second consecutive call must stay clean
$mr2 = api('POST', "$BASE/api/notifications.php", $dc, ['action'=>'mark_all_read']);
rep('24b. mark_all_read idempotent (on-open repeat)', $mr2['status']==='success' && ($mr2['data']['unread_count']??-1)===0, json_encode($mr2));
$drv = q($db, "SELECT COUNT(*) AS c FROM activity_logs WHERE user_id=? AND is_read=0", [$drvId]);
$own = q($db, "SELECT COUNT(*) AS c FROM activity_logs WHERE owner_id=1 AND is_read=0");
rep('25. cross-role isolation', (int)$drv[0]['c']===0 && (int)$own[0]['c']===$ownerUnreadBefore, 'driverUnread='.$drv[0]['c'].' ownerUnread='.$own[0]['c'].' (before='.$ownerUnreadBefore.')');
// 26: clear is mark-as-read, never delete
$total = q($db, "SELECT COUNT(*) AS c FROM activity_logs");
rep('26. non-destructive', (int)$total[0]['c'] > 0, 'total_rows='.$total[0]['c']);
// 27-27b: auth regression guard — APP_URL once pointed at a nonexistent legacy dir
// ('ev-charging-station'), bouncing unauthenticated/expired users to a 404 instead of
// the login page. Fixed 2026-08-23 (config.php APP_URL -> http://localhost/EE).
$ch = curl_init("$BASE/public/dashboard/driver.php");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true]); // no cookie jar: truly anonymous
curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$loc  = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
curl_close($ch);
rep('27. unauth redirect hits real login', $code === 302 && $loc === "$BASE/login.php", 'code='.$code.' loc='.var_export($loc, true));
$ch = curl_init("$BASE/login.php?session=expired");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true]);
$body = (string) curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
rep('27b. expired-variant login lands 200', $http === 200 && strpos($body, 'login-form') !== false, 'status='.$http);
// 28-30b: Remember Me end-to-end (hash-at-rest, auto-login + rotation, replay kill,
// tamper fail-closed, logout wipes all devices)
$hdrs = [];
$rt = tempnam(sys_get_temp_dir(), 'rt');
$ch = curl_init("$BASE/api/auth/login.php");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
    CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
    CURLOPT_POSTFIELDS=>json_encode(['email'=>'driver1@example.com','password'=>'Test@123','user_type'=>'driver','remember'=>true]),
    CURLOPT_COOKIEJAR=>$rt, CURLOPT_COOKIEFILE=>$rt,
    CURLOPT_USERAGENT=>'IntegrationTest/1.0',
    CURLOPT_HEADERFUNCTION=>function($c,$line) use (&$hdrs){ $hdrs[]=$line; return strlen($line); }]);
$lr = json_decode(curl_exec($ch), true);
curl_close($ch);
$rawTok = null;
foreach ($hdrs as $h) { if (preg_match('/^Set-Cookie: remember_token=([^;\r\n]+)/i', $h, $m)) $rawTok = $m[1]; }
$drvId = $lr['data']['user_id'] ?? 0;
$st = q($db, "SELECT token FROM remember_tokens WHERE user_id=? AND user_type='driver' ORDER BY id DESC LIMIT 1", [$drvId]);
$isHash = $rawTok !== null && $st && hash('sha256', $rawTok) === $st[0]['token'];
rep('28. remember login stores HASH (never raw)', $isHash, 'cookie='.substr((string)$rawTok,0,8).'... sha256-match='.var_export($isHash,true));
$ch = curl_init("$BASE/public/dashboard/driver.php");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_COOKIE=>"remember_token=$rawTok", CURLOPT_USERAGENT=>'IntegrationTest/1.0']);
curl_exec($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
$new = q($db, "SELECT token FROM remember_tokens WHERE user_id=? AND user_type='driver'", [$drvId]);
$rotated = !empty($new) && $new[0]['token'] !== hash('sha256', $rawTok);
rep('29. auto-login rescues + rotates token', $http === 200 && $rotated, 'status='.$http.' rotated='.var_export($rotated,true));
$ch = curl_init("$BASE/public/dashboard/driver.php");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_COOKIE=>"remember_token=$rawTok", CURLOPT_USERAGENT=>'IntegrationTest/1.0']);
curl_exec($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
rep('29b. replay of consumed token rejected', $http === 302, 'status='.$http);
$fake = str_repeat('ab', 32);
$ch = curl_init("$BASE/public/dashboard/driver.php");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_COOKIE=>"remember_token=$fake", CURLOPT_USERAGENT=>'IntegrationTest/1.0']);
curl_exec($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
rep('30. tampered remember cookie fails closed', $http === 302, 'status='.$http);
$cntBefore = (int)q($db, "SELECT COUNT(*) c FROM remember_tokens WHERE user_id=? AND user_type='driver'", [$drvId])[0]['c'];
api('POST', "$BASE/api/auth/login.php", $rt, ['email'=>'driver1@example.com','password'=>'Test@123','user_type'=>'driver','remember'=>true]);
api('GET', "$BASE/public/logout.php", $rt);
$cntAfter = (int)q($db, "SELECT COUNT(*) c FROM remember_tokens WHERE user_id=? AND user_type='driver'", [$drvId])[0]['c'];
rep('30b. logout wipes remembered devices', $cntBefore >= 1 && $cntAfter === 0, "rows_before=$cntBefore rows_after=$cntAfter");
if (is_file($rt)) @unlink($rt);
echo "DONE\n";
