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
$CSRF_TOKENS=[];
function csrfFor($BASE,$jar,$dash,$force=false){global $CSRF_TOKENS;if(!$force&&isset($CSRF_TOKENS[$jar]))return $CSRF_TOKENS[$jar];$ch=curl_init("$BASE/$dash");curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$jar,CURLOPT_COOKIEJAR=>$jar,CURLOPT_USERAGENT=>'IntegrationTest/1.0',CURLOPT_FOLLOWLOCATION=>true]);$h=(string)curl_exec($ch);curl_close($ch);preg_match('/name="csrf-token" content="([0-9a-f]{64})"/',$h,$m);return $CSRF_TOKENS[$jar]=($m[1]??'');}
function api($m,$u,$c,$p=null){global $CSRF_TOKENS,$BASE;$hdrs=['Content-Type: application/json'];if(strtoupper($m)!=='GET'&&strpos($u,'/api/auth/')!==false){$t=csrfFor($BASE,$c,'public/login.php',true);if($t===''){$dash2=($c===$GLOBALS['oc'])?'public/dashboard/owner.php':'public/dashboard/driver.php';$t=csrfFor($BASE,$c,$dash2,true);}if($t!=='')$hdrs[]='X-CSRF-Token: '.$t;}elseif(strtoupper($m)!=='GET'&&isset($CSRF_TOKENS[$c])){$hdrs[]='X-CSRF-Token: '.$CSRF_TOKENS[$c];}$ch=curl_init($u);$o=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$m,CURLOPT_HTTPHEADER=>$hdrs,CURLOPT_COOKIEJAR=>$c,CURLOPT_COOKIEFILE=>$c,CURLOPT_USERAGENT=>'IntegrationTest/1.0'];if($p!==null)$o[CURLOPT_POSTFIELDS]=json_encode($p);curl_setopt_array($ch,$o);$r=curl_exec($ch);curl_close($ch);return json_decode($r,true);}
function q($db,$s,$p=[]){$st=$db->prepare($s);$st->execute($p);return $st->fetchAll();}
// Minimal authed-POST helper shaped exactly like the verified standalone probe
function tpost($u,$p,$tok,$jar){$ch=curl_init($u);$o=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>'POST',CURLOPT_HTTPHEADER=>$tok===null?['Content-Type: application/json']:['Content-Type: application/json',"X-CSRF-Token: $tok"],CURLOPT_POSTFIELDS=>json_encode($p),CURLOPT_COOKIEJAR=>$jar,CURLOPT_COOKIEFILE=>$jar,CURLOPT_USERAGENT=>'IntegrationTest/1.0'];curl_setopt_array($ch,$o);$r=curl_exec($ch);$c=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);return[$c,json_decode((string)$r,true)];}
function rep($s,$p,$d){echo($p?"PASS":"FAIL")." | $s | $d\n";}
$dc=__DIR__.'/dc.txt';$oc=__DIR__.'/oc.txt';@unlink($dc);@unlink($oc);
$l=api('POST',"$BASE/api/auth/login.php",$dc,['email'=>'driver1@example.com','password'=>'Test@123','user_type'=>'driver']);
rep('Login driver',$l['status']==='success',json_encode($l));
$lo=api('POST',"$BASE/api/auth/login.php",$oc,['email'=>'owner1@example.com','password'=>'Test@123','user_type'=>'owner']);
rep('Login owner',$lo['status']==='success',json_encode($lo));
csrfFor($BASE,$dc,'public/dashboard/driver.php',true); // prime per-session CSRF tokens for all later POSTs (force: login rotated the session id)
csrfFor($BASE,$oc,'public/dashboard/owner.php',true);
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
// 4b/4c: owner booking notification fires exactly once on real confirmation
$own=q($db,"SELECT COUNT(*) c FROM activity_logs WHERE action='booking_created' AND resource_type='booking' AND resource_id=?",[$bid]);
rep('4b. owner booking_created written once',$own[0]['c']===1,'count='.$own[0]['c']);
$c2=api('POST',"$BASE/api/bookings.php",$dc,['action'=>'confirm_payment','booking_id'=>$bid]);
$own2=q($db,"SELECT COUNT(*) c FROM activity_logs WHERE action='booking_created' AND resource_type='booking' AND resource_id=?",[$bid]);
rep('4c. duplicate confirm cannot re-notify (state gate)',$own2[0]['c']===1,'confirm2='.$c2['status'].' count='.$own2[0]['c']);
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
// 19b-19g: PHASE 1 REVIEWS — create/read/average/duplicate/ineligible/XSS-raw contract
// (booking $bid2 is 'stopped' = finished; token force-refreshed: session rotated at the re-login)
$st_id = q($db, "SELECT c.station_id FROM bookings b JOIN chargers c ON b.charger_id=c.id WHERE b.id=?", [$bid2])[0]['station_id'];
q($db, "DELETE FROM ratings_reviews WHERE station_id=?", [$st_id]); // suite owns the target station's review state: deterministic average/list (cross-run accumulation otherwise breaks 19d/19g)
csrfFor($BASE,$dc,'public/dashboard/driver.php',true);
$rr = api('POST', "$BASE/api/reviews.php", $dc, ['booking_id'=>$bid2, 'rating'=>4, 'comment'=>'Charging worked well <script>alert(1)</script>']);
rep('19b. review create', $rr['status']==='success', json_encode($rr));
$rrRow = q($db, "SELECT station_id, rating, comment FROM ratings_reviews WHERE booking_id=?", [$bid2]);
rep('19c. review row + raw XSS payload stored (render escapes client-side)', count($rrRow)===1 && $rrRow[0]['station_id']==$st_id && $rrRow[0]['rating']==4 && strpos($rrRow[0]['comment'], '<script>')!==false, json_encode($rrRow));
$avg62 = q($db, "SELECT average_rating FROM stations WHERE id=?", [$st_id])[0]['average_rating'];
rep('19d. average_rating recalculated in-transaction', floatval($avg62)===4.0, 'avg='.$avg62);
$dup = api('POST', "$BASE/api/reviews.php", $dc, ['booking_id'=>$bid2, 'rating'=>5, 'comment'=>'again']);
rep('19e. duplicate review blocked', $dup['status']==='error' && strpos($dup['message'],'already reviewed')!==false, json_encode($dup));
$i19 = api('POST', "$BASE/api/bookings.php", $dc, ['action'=>'initiate_payment','charger_id'=>1]);
$bid19 = $i19['data']['booking_id'] ?? 0;
$inel = api('POST', "$BASE/api/reviews.php", $dc, ['booking_id'=>$bid19, 'rating'=>5, 'comment'=>'x']);
rep('19f. ineligible (pending_payment) review blocked', $inel['status']==='error', json_encode($inel));
$list = api('GET', "$BASE/api/reviews.php?station_id=$st_id", $dc);
rep('19g. station review list + average', $list['status']==='success' && count($list['data']['reviews'])>=1 && floatval($list['data']['average_rating'])===4.0, json_encode($list['data']));
// 19l: 24-hour review window — a finished booking whose updated_at is >24h old must be ineligible.
// Fixture inserted directly (not via the multi-step flow) so the test depends only on the
// window logic, not on charger queue state left by earlier tests. Backdated updated_at
// simulates a session that ended >24h ago; the API must reject with the window-closed message.
$drvId = intval(q($db, "SELECT id FROM users WHERE email='driver1@example.com'")[0]['id'] ?? 0);
$chId = intval(q($db, "SELECT id FROM chargers WHERE station_id=? LIMIT 1", [$st_id])[0]['id'] ?? 0);
$winComment = 'Window-expiry probe ' . uniqid();
$db->prepare("INSERT INTO bookings (user_id, charger_id, status, payment_status, updated_at) VALUES (?, ?, 'stopped', 'completed', DATE_SUB(NOW(), INTERVAL 25 HOUR))")
   ->execute([$drvId, $chId]);
$bidQ = intval($db->lastInsertId());
$winExpired = api('POST', "$BASE/api/reviews.php", $dc, ['booking_id'=>$bidQ, 'rating'=>3, 'comment'=>$winComment]);
rep('19l. review blocked after 24h window', ($winExpired['status'] ?? '') === 'error' && strpos($winExpired['message'] ?? '', '24-hour review window') !== false, json_encode($winExpired));
// cleanup the window-probe booking
q($db, "DELETE FROM bookings WHERE id=?", [$bidQ]);
// fixture cleanup for the ineligible-probe booking (keep suite drift minimal)
q($db, "DELETE FROM payment_transactions WHERE booking_id=?", [$bid19]);
q($db, "DELETE FROM bookings WHERE id=?", [$bid19]);
// 20: FAVORITES — add favorite, confirm persisted, idempotent duplicate, remove, confirm gone.
// Exercises the new add branch + method-scoped CSRF gate (action=add/remove, form-encoded POST).
$tok = csrfFor($BASE, $dc, 'public/dashboard/driver.php', true);
function favPost($BASE, $dc, $tok, $action, $stationId) {
    $ch = curl_init("$BASE/public/dashboard/sections/favorites.php");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['action'=>$action, 'station_id'=>$stationId]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', "X-CSRF-Token: $tok"],
        CURLOPT_COOKIEJAR => $dc, CURLOPT_COOKIEFILE => $dc, CURLOPT_USERAGENT => 'IntegrationTest/1.0',
    ]);
    $r = curl_exec($ch); curl_close($ch);
    return json_decode((string)$r, true);
}
q($db, "DELETE FROM favorites WHERE user_id=? AND station_id=?", [$drvId, $st_id]); // clean state
$favAdd = favPost($BASE, $dc, $tok, 'add', $st_id);
rep('20a. add favorite', ($favAdd['status'] ?? '') === 'success', json_encode($favAdd));
$favRow = q($db, "SELECT id FROM favorites WHERE user_id=? AND station_id=?", [$drvId, $st_id]);
rep('20b. favorite persisted', count($favRow) === 1, json_encode($favRow));
$favDup = favPost($BASE, $dc, $tok, 'add', $st_id); // INSERT IGNORE: must succeed, not error
rep('20c. duplicate add idempotent', ($favDup['status'] ?? '') === 'success', json_encode($favDup));
$favRow2 = q($db, "SELECT COUNT(*) c FROM favorites WHERE user_id=? AND station_id=?", [$drvId, $st_id]);
rep('20d. no duplicate row', (int)$favRow2[0]['c'] === 1, json_encode($favRow2));
$favRem = favPost($BASE, $dc, $tok, 'remove', $st_id);
rep('20e. remove favorite', ($favRem['status'] ?? '') === 'success', json_encode($favRem));
$favRow3 = q($db, "SELECT id FROM favorites WHERE user_id=? AND station_id=?", [$drvId, $st_id]);
rep('20f. favorite removed', count($favRow3) === 0, json_encode($favRow3));
q($db, "DELETE FROM favorites WHERE user_id=? AND station_id=?", [$drvId, $st_id]); // cleanup
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
// 24-25: driver marks all read → driver rows flip, owner rows untouched
$mr = api('POST', "$BASE/api/notifications.php", $dc, ['action'=>'mark_all_read']);
rep('24. mark_all_read driver', $mr['status']==='success' && ($mr['data']['unread_count']??-1)===0, json_encode($mr));
// 24b: on-open behavior fires this repeatedly — second consecutive call must stay clean
$mr2 = api('POST', "$BASE/api/notifications.php", $dc, ['action'=>'mark_all_read']);
rep('24b. mark_all_read idempotent (on-open repeat)', $mr2['status']==='success' && ($mr2['data']['unread_count']??-1)===0, json_encode($mr2));
// 25: cross-role isolation measured THROUGH the product's own scoping (API unread counts),
// so legacy unscoped rows (e.g. old google_login entries) can't produce false positives.
// NOTE: booking_created rows are intentionally dual-visible (owner_id scope + booking
// ownership scope), so driver mark_all_read legitimately flips them. Isolation is proven
// with a fresh owner-only seed: driver must not see it, owner must.
$ownId = (int)q($db, "SELECT s.owner_id FROM bookings b JOIN chargers c ON b.charger_id=c.id JOIN stations s ON c.station_id=s.id WHERE b.id=?", [$bid])[0]['owner_id'];
$db->prepare("INSERT INTO activity_logs (owner_id, action, resource_type, resource_id, details) VALUES (?, 'test_owner_probe', 'station', 1, 'SEED owner probe')")->execute([$ownId]);
$noD2 = api('GET', "$BASE/api/notifications.php", $dc);
$noO2 = api('GET', "$BASE/api/notifications.php", $oc);
$dU = (int)($noD2['data']['unread_count'] ?? -1);
$oU = (int)($noO2['data']['unread_count'] ?? -1);
$probeSeen = in_array('test_owner_probe', array_column($noO2['data']['items'] ?? [], 'action'), true);
rep('25z. cross-role isolation (scoped)', $dU === 0 && $probeSeen && $oU >= 1, "driverUnread=$dU ownerUnread=$oU probeSeen=".var_export($probeSeen, true));
$db->exec("DELETE FROM activity_logs WHERE action='test_owner_probe' AND details='SEED owner probe'");
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
$tok28 = csrfFor($BASE, $rt, 'public/login.php', true);
$ch = curl_init("$BASE/api/auth/login.php");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
    CURLOPT_HTTPHEADER=>['Content-Type: application/json', "X-CSRF-Token: $tok28"],
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
// 30c/30d: the legacy open-redirect endpoint api/auth/logout.php was DELETED (zero callers).
// 30c asserts the security essence: the URL must never produce a redirect again. (Missing
// paths under /EE currently 500 via the pre-existing AH00124 rewrite fragility - that is
// ledgered separately - see PROJECT_REPORT §16 backlog cross-reference; do NOT change this
// check to expect 404 until that fragility is fixed. What matters here is NO 3xx and NO
// attacker-controlled Location.)
$ch = curl_init("$BASE/api/auth/logout.php?redirect=https://evil.example.com");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true]);
curl_exec($ch);
$c30c = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$l30c = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
curl_close($ch);
rep('30c. deleted open-redirect endpoint cannot redirect', $c30c !== 302 && empty($l30c), 'code='.$c30c.' loc='.var_export($l30c, true));
$ch = curl_init("$BASE/public/logout.php");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true]);
curl_exec($ch);
$c30d = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$l30d = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
curl_close($ch);
rep('30d. public logout redirects in-app only', $c30d === 302 && $l30d === "$BASE/public/index.php", 'code='.$c30d.' loc='.var_export($l30d, true));
// 30e-30i: login-CSRF guard live on all four auth POST endpoints (negative checks ride
// direct tpost with tok=null so api()'s auto-primer cannot mask them; positive pass-through
// for register/google is proven by 44b/65/67 reaching their own handler errors).
$ej=__DIR__.'/ej.txt';@unlink($ej);
list($cE,) = tpost("$BASE/api/auth/login.php", ['email'=>'driver1@example.com','password'=>'Test@123'], null, $ej);
rep('30e. login without token -> 403', $cE === 403, 'code='.$cE);
$tE = csrfFor($BASE,$ej,'public/login.php',true);
list($cF,$jF) = tpost("$BASE/api/auth/login.php", ['email'=>'driver1@example.com','password'=>'Test@123','user_type'=>'driver'], $tE, $ej);
rep('30f. login with page token -> success', $cF === 200 && ($jF['status'] ?? '') === 'success', 'code='.$cF.' resp='.json_encode($jF));
list($cG,) = tpost("$BASE/api/auth/register.php", ['email'=>'x@gmail.com'], null, $ej);
rep('30g. register without token -> 403', $cG === 403, 'code='.$cG);
list($cH,) = tpost("$BASE/api/auth/otp.php", ['action'=>'send_otp','email'=>'x@gmail.com'], null, $ej);
rep('30h. otp without token -> 403', $cH === 403, 'code='.$cH);
list($cI,) = tpost("$BASE/api/auth/google.php", ['token'=>'x'], null, $ej);
rep('30i. google without token -> 403', $cI === 403, 'code='.$cI);
@unlink($ej);
if (is_file($rt)) @unlink($rt);

// ===== 31-37: LOGIN THROTTLING (two-layer brute-force protection) =====
// SEMANTICS (user-approved): thresholds are COUNT(*) >= N evaluated BEFORE the current
// failed attempt is recorded, so N wrong attempts execute and request N+1 is the FIRST
// 429 (standard rate-limit semantics; test wording fixed accordingly).
// LIMITATION: cross-IP isolation cannot be exercised from this single-host suite —
// every HTTP request originates from this host's single loopback IP, so only its lockout behavior is
// runtime-tested; that genuinely different IPs stay unaffected follows from the
// `ip_address = ?` WHERE clauses (verified by code review), not an executed case.
function treq($u, $p, $extraHeaders = [], $jar = null) {
    global $BASE;
    $h = [];
    $tmpJar = null;
    if ($jar === null) { $tmpJar = tempnam(sys_get_temp_dir(), 'tj'); $jar = $tmpJar; }
    // login-CSRF: mint a guest token from the login page for this request's jar
    // (follows expiry redirects; throttle semantics unchanged - counting is by email+IP)
    $tok = csrfFor($BASE, $jar, 'public/login.php', true);
    $extraHeaders[] = "X-CSRF-Token: $tok";
    $ch = curl_init($u);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $extraHeaders),
        CURLOPT_POSTFIELDS => json_encode($p),
        CURLOPT_HEADERFUNCTION => function ($c, $l) use (&$h) { $h[] = $l; return strlen($l); }]);
    if ($jar !== null) { curl_setopt($ch, CURLOPT_COOKIEJAR, $jar); curl_setopt($ch, CURLOPT_COOKIEFILE, $jar); }
    curl_setopt($ch, CURLINFO_HEADER_OUT, true);
    $b = curl_exec($ch);
    $reqOut = curl_getinfo($ch, CURLINFO_HEADER_OUT);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($tmpJar !== null) @unlink($tmpJar);
    $ra = null;
    foreach ($h as $x) { if (preg_match('/^Retry-After:\s*(\d+)/i', $x, $m)) $ra = (int)$m[1]; }
    return [$code, json_decode((string)$b, true), $ra, $h, $reqOut];
}
$LI = "$BASE/api/auth/login.php";
q($db, "DELETE FROM login_attempts");
// Discover the loopback IP exactly as Apache records it ('::1' on IPv6 hosts, '127.0.0.1' elsewhere)
treq($LI, ['email' => 'ip-probe@example.com', 'password' => 'zz', 'user_type' => 'driver']);
$TEST_IP = (string)(q($db, "SELECT ip_address FROM login_attempts WHERE email=? LIMIT 1", ['ip-probe@example.com'])[0]['ip_address'] ?? '');
q($db, "DELETE FROM login_attempts WHERE email=?", ['ip-probe@example.com']); // probe row must not skew budgets
// 31: legit login works at zero prior failures
$r = treq($LI, ['email' => 'driver1@example.com', 'password' => 'Test@123', 'user_type' => 'driver']);
rep('31. legit login at zero prior failures', $r[0] === 200 && ($r[1]['status'] ?? '') === 'success', 'http=' . $r[0]);
// 32: five wrong passwords on one email all execute; SIXTH request is the first 429 (Layer 1)
$codes = [];
for ($i = 1; $i <= 5; $i++) { $codes[] = treq($LI, ['email' => 'driver1@example.com', 'password' => "wrong$i", 'user_type' => 'driver'])[0]; }
$r6 = treq($LI, ['email' => 'driver1@example.com', 'password' => 'wrong6', 'user_type' => 'driver']);
rep('32. after 5 recorded fails, 6th req = 429 + Retry-After 900 (Layer 1)',
    $codes === [200, 200, 200, 200, 200] && $r6[0] === 429 && $r6[2] === 900 && strpos($r6[1]['message'] ?? '', 'Too many login attempts') !== false,
    'burst=' . implode(',', $codes) . ' 6th_http=' . $r6[0] . ' retry_after=' . var_export($r6[2], true));
// 32b: the 429 body must parse STANDALONE into the exact shape the login page JS renders
rep('32b. 429 body parses standalone (shape the UI toasts)',
    is_array($r6[1]) && ($r6[1]['status'] ?? '') === 'error' && ($r6[1]['message'] ?? '') === 'Too many login attempts. Please try again later.',
    'decoded=' . json_encode($r6[1]));
// 33: CORRECT password submitted while pair-locked -> still 429 (lockout wins over valid creds)
$r = treq($LI, ['email' => 'driver1@example.com', 'password' => 'Test@123', 'user_type' => 'driver']);
rep('33. valid creds still 429 while pair-locked', $r[0] === 429, 'http=' . $r[0]);
// 34: simulate window expiry by directly deleting the pair rows via PDO -> correct login succeeds
q($db, "DELETE FROM login_attempts WHERE email=? AND ip_address=?", ['driver1@example.com', $TEST_IP]);
$r = treq($LI, ['email' => 'driver1@example.com', 'password' => 'Test@123', 'user_type' => 'driver']);
rep('34. window-expiry sim -> correct login succeeds', $r[0] === 200 && ($r[1]['status'] ?? '') === 'success', 'http=' . $r[0]);
// 35: successful login reset the pair counter -> FULL budget again (five executes, sixth locks)
$codes = [];
for ($i = 1; $i <= 5; $i++) { $codes[] = treq($LI, ['email' => 'driver1@example.com', 'password' => "wrong$i", 'user_type' => 'driver'])[0]; }
$r6 = treq($LI, ['email' => 'driver1@example.com', 'password' => 'wrong6', 'user_type' => 'driver']);
rep('35. post-reset budget is full (not partially counted)', $codes === [200, 200, 200, 200, 200] && $r6[0] === 429,
    'burst=' . implode(',', $codes) . ' 6th_http=' . $r6[0]);
// 36: password SPRAY — many distinct emails, one IP. IP-wide total starts at 5 (from 35);
// push it to exactly 20 with 15 more real-HTTP failures across distinct fake emails...
$sprayCodes = [];
for ($i = 1; $i <= 15; $i++) { $sprayCodes[] = treq($LI, ['email' => "spray$i@example.com", 'password' => 'guess', 'user_type' => 'driver'])[0]; }
$ipTotal = (int)q($db, "SELECT COUNT(*) c FROM login_attempts WHERE ip_address=?", [$TEST_IP])[0]['c'];
// ...then a BRAND NEW, never-before-tried email from the same IP must be instantly 429'd (Layer 2)
$r = treq($LI, ['email' => 'fresh-face@example.com', 'password' => 'whatever', 'user_type' => 'driver']);
rep('36. spray fills IP net; brand-new email instant 429 (Layer 2)',
    $sprayCodes === array_fill(0, 15, 200) && $ipTotal === 20 && $r[0] === 429,
    'spray=' . implode(',', $sprayCodes) . " ip_total=$ipTotal fresh_http={$r[0]}");
// 37: prove Layer 2 ALONE blocks correct creds. Purge driver1's pair rows first, then
// top the IP-wide net back up to 20 rows held entirely by OTHER (fake) emails — mirroring
// an attacker whose spray failures sit in the table regardless of the victim's own state.
q($db, "DELETE FROM login_attempts WHERE email=? AND ip_address=?", ['driver1@example.com', $TEST_IP]);
$have = (int)q($db, "SELECT COUNT(*) c FROM login_attempts WHERE ip_address=?", [$TEST_IP])[0]['c'];
$fill = $db->prepare("INSERT INTO login_attempts (email, ip_address, user_type) VALUES (?, ?, 'driver')");
for ($i = $have + 1; $i <= 20; $i++) { $fill->execute(["netfill$i@example.com", $TEST_IP]); }
$r = treq($LI, ['email' => 'driver1@example.com', 'password' => 'Test@123', 'user_type' => 'driver']);
rep('37. coarse IP net overrides valid creds (pure Layer 2)', $r[0] === 429,
    'http=' . $r[0] . ' pair_rows=' . (int)q($db, "SELECT COUNT(*) c FROM login_attempts WHERE email=?", ['driver1@example.com'])[0]['c']
    . ' other_email_rows=' . (int)q($db, "SELECT COUNT(*) c FROM login_attempts WHERE ip_address=? AND email<>?", [$TEST_IP, 'driver1@example.com'])[0]['c']);

// ===== 38-43: CSRF PROTECTION (session-bound token via X-CSRF-Token header) =====
q($db, 'DELETE FROM login_attempts'); // keep throttle state from checks 32-37 out of this section
// (check 42 removed 2026-08-29: it asserted login succeeds WITHOUT a CSRF token - the exact
// behavior the login-CSRF fix now forbids. Superseded by 30e/30f.)
// 38a: delivery path — token must appear as meta in the rendered dashboard shell HTML
$ch = curl_init("$BASE/public/dashboard/driver.php");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $dc, CURLOPT_USERAGENT => 'IntegrationTest/1.0']);
$html = (string) curl_exec($ch);
curl_close($ch);
preg_match('/name="csrf-token" content="([0-9a-f]{64})"/', $html, $m);
$tok = $m[1] ?? '';
rep('38a. shell HTML delivers session-bound meta token', $tok !== '' && $tok === ($CSRF_TOKENS[$dc] ?? ''), 'len=' . strlen($tok) . ' matches_primed=' . var_export($tok === ($CSRF_TOKENS[$dc] ?? ''), true));
// 38c/38d: fragment GET-renderability regression guard — the 2026-08-29 incident
// (d8ea54a placed Csrf::validate() unconditionally on the dual-purpose profile
// fragments, 403-ing the GET that renders the profile form; invisible to the
// suite because nothing ever GET-ed a fragment). Authenticated GET must render
// the form, never the token-error body.
$ch = curl_init("$BASE/public/dashboard/sections/profile.php");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $dc, CURLOPT_USERAGENT => 'IntegrationTest/1.0']);
$b38c = (string) curl_exec($ch);
$c38c = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
rep('38c. driver profile fragment GET renders (no CSRF-gate regression)', $c38c === 200 && strpos($b38c, 'driver-profile-form') !== false && strpos($b38c, 'Invalid security token') === false, 'code=' . $c38c . ' form=' . var_export(strpos($b38c, 'driver-profile-form') !== false, true));
$ch = curl_init("$BASE/public/dashboard/owner_sections/profile.php");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $oc, CURLOPT_USERAGENT => 'IntegrationTest/1.0']);
$b38d = (string) curl_exec($ch);
$c38d = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
rep('38d. owner profile fragment GET renders (no CSRF-gate regression)', $c38d === 200 && strpos($b38d, 'owner-profile-form') !== false && strpos($b38d, 'Invalid security token') === false, 'code=' . $c38d . ' form=' . var_export(strpos($b38d, 'owner-profile-form') !== false, true));
// 38: valid token accepted on a state-changing endpoint
$r38 = tpost("$BASE/api/notifications.php", ['action' => 'mark_all_read'], $tok, $dc);
rep('38. valid token accepted', $r38[0] === 200 && ($r38[1]['status'] ?? '') === 'success', 'http=' . $r38[0]);
// 39: missing token -> distinct 403 (not the generic error shape)
$r39 = tpost("$BASE/api/notifications.php", ['action' => 'mark_all_read'], null, $dc);
rep('39. missing token -> 403 + distinct message', $r39[0] === 403 && strpos($r39[1]['message'] ?? '', 'Invalid security token') !== false, 'http=' . $r39[0] . ' body=' . json_encode($r39[1]) . ' loc=' . json_encode(preg_grep('/^Location:/i', $r39[3] ?? [])));
// 40: tampered token -> 403
$tam = $tok !== '' ? substr($tok, 0, -1) . (substr($tok, -1) === 'a' ? 'b' : 'a') : 'deadbeef';
$r40 = tpost("$BASE/api/notifications.php", ['action' => 'mark_all_read'], $tam, $dc);
rep('40. tampered token -> 403', $r40[0] === 403, 'http=' . $r40[0]);
// 41: cross-session binding — the OWNER session's perfectly valid token must fail on the DRIVER session
$r41 = tpost("$BASE/api/notifications.php", ['action' => 'mark_all_read'], ($CSRF_TOKENS[$oc] ?? 'notoken'), $dc);
rep('41. foreign-session token -> 403', $r41[0] === 403 && (($CSRF_TOKENS[$oc] ?? '') !== $tok), 'http=' . $r41[0]);
// 43: scope guard — GET endpoints stay headerless-friendly
$ch = curl_init("$BASE/api/stats.php");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $dc, CURLOPT_USERAGENT => 'IntegrationTest/1.0']);
$b43 = (string) curl_exec($ch);
$c43 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
rep('43. GET stats unaffected by CSRF scope', $c43 === 200 && strpos($b43, '"success"') !== false, 'http=' . $c43);

// ===== 44-48: SERVER-SIDE REGISTRATION VALIDATION HARDENING =====
// Validation runs BEFORE the verified-OTP gate, so each rejection below proves its rule
// without needing SMTP. Check 48 (valid-but-unverified) doubles as regression proof that
// no earlier rule misfires on a legitimate payload.
$rj = __DIR__ . '/rj.txt';
@unlink($rj);
$regBase = ['user_type' => 'driver', 'email' => 'regcheck@gmail.com', 'password' => 'Valid1Pass', 'name' => 'Reg Checker', 'phone' => '+977 9812345678', 'car_model' => 'Tesla Model 3', 'battery_capacity' => '40'];
$regExpect = function ($payload, $needle) use ($BASE, $rj) {
    $r = api('POST', "$BASE/api/auth/register.php", $rj, $payload);
    return [$r, strpos($r['message'] ?? '', $needle) !== false];
};
[$r, $ok] = $regExpect(array_merge($regBase, ['password' => 'valid1pass']), 'uppercase');
rep('44a. register rejects missing uppercase', $ok, json_encode($r));
[$r, $ok] = $regExpect(array_merge($regBase, ['password' => 'ValidPass']), 'at least one number');
rep('44b. register rejects missing number', $ok, json_encode($r));
[$r, $ok] = $regExpect(array_merge($regBase, ['name' => 'A']), 'between 2 and 100');
rep('45a. register rejects short name', $ok, json_encode($r));
[$r, $ok] = $regExpect(array_merge($regBase, ['name' => str_repeat('A', 101)]), 'between 2 and 100');
rep('45b. register rejects overlong name', $ok, json_encode($r));
[$r, $ok] = $regExpect(array_merge($regBase, ['battery_capacity' => '0']), 'positive number');
rep('46. driver battery=0 rejected', $ok, json_encode($r));
$ownerBad = ['user_type' => 'owner', 'email' => 'regcheck@gmail.com', 'password' => 'Valid1Pass', 'name' => 'Reg Checker', 'phone' => '+977 9812345678', 'company_name' => '', 'bank_account' => '1234567890'];
[$r, $ok] = $regExpect($ownerBad, 'Company name is required');
rep('47a. owner empty company rejected', $ok, json_encode($r));
[$r, $ok] = $regExpect(array_merge($ownerBad, ['company_name' => 'Green Energy Ltd', 'bank_account' => 'ABC123']), '5-20 digits');
rep('47b. owner non-digit bank rejected', $ok, json_encode($r));
[$r48, $ok48] = $regExpect($regBase, 'Email not verified');
rep('48. valid payload clears all rules -> reaches OTP gate', $ok48 && ($r48['status'] ?? '') === 'error', json_encode($r48));
@unlink($rj);

// ===== 49-57: SUPPORT TICKETS (driver/owner submit; admin queue, reply, status) =====
$adminHash = password_hash('AdminTest@123', PASSWORD_BCRYPT);
$db->prepare("INSERT INTO admins (email, password, name, role) VALUES (?, ?, ?, 'super_admin')
              ON DUPLICATE KEY UPDATE password = VALUES(password)")
   ->execute(['supporttest-admin@evcharge.com', $adminHash, 'Support Test Admin']);
$ac = __DIR__ . '/ac.txt';
@unlink($ac);
api('POST', "$BASE/api/auth/login.php", $ac, ['email' => 'supporttest-admin@evcharge.com', 'password' => 'AdminTest@123', 'user_type' => 'admin']);
csrfFor($BASE, $ac, 'public/dashboard/admin.php', true);

// ===== 19h-19k: REVIEWS PHASE 2 — owner flag, admin moderation, owner warnings =====
$revId = intval(q($db, "SELECT id FROM ratings_reviews WHERE booking_id=?", [$bid2])[0]['id'] ?? 0);
$ownId = intval(q($db, "SELECT owner_id FROM stations WHERE id=?", [$st_id])[0]['owner_id'] ?? 0);
$rrF = api('POST', "$BASE/api/reviews.php", $oc, ['action' => 'flag', 'review_id' => $revId, 'reason' => 'Suite: fabricated complaint']);
rep('19h. owner flags review', ($rrF['status'] ?? '') === 'success', json_encode($rrF));
$fl = q($db, "SELECT is_flagged, flag_reason FROM ratings_reviews WHERE id=?", [$revId])[0] ?? [];
$flNotified = count(q($db, "SELECT id FROM activity_logs WHERE action='review_flagged' AND resource_id=?", [$revId])) === 1;
rep('19h2. flag persisted + author notified', ($fl['is_flagged'] ?? 0) == 1 && strpos($fl['flag_reason'] ?? '', 'fabricated') !== false && $flNotified, json_encode($fl));
$md = api('POST', "$BASE/api/reviews.php", $ac, ['action' => 'moderate', 'review_id' => $revId, 'decision' => 'dismiss']);
rep('19i. admin dismisses flag', ($md['status'] ?? '') === 'success', json_encode($md));
$fl2 = q($db, "SELECT is_flagged, is_deleted FROM ratings_reviews WHERE id=?", [$revId])[0] ?? [];
rep('19i2. dismissed = visible again', ($fl2['is_flagged'] ?? 1) == 0 && ($fl2['is_deleted'] ?? 1) == 0, json_encode($fl2));
q($db, "UPDATE owners SET warning_count=0 WHERE id=?", [$ownId]); // deterministic 0->1 for 19j2 regardless of prior warnings
$wn = api('POST', "$BASE/api/reviews.php", $ac, ['action' => 'warn', 'owner_id' => $ownId, 'reason' => 'Suite: formal warning test']);
rep('19j. admin warns owner', ($wn['status'] ?? '') === 'success', json_encode($wn));
$wc = intval(q($db, "SELECT warning_count FROM owners WHERE id=?", [$ownId])[0]['warning_count'] ?? -1);
rep('19j2. warning_count incremented', $wc === 1, 'wc=' . $wc);
// 19k: capability gate — an admin WITHOUT can_moderate_reviews cannot warn (or moderate)
$db->prepare("INSERT INTO admins (email, password, name, role, can_moderate_reviews) VALUES (?, ?, ?, 'super_admin', 0)")
   ->execute(['nomod-admin@evcharge.com', password_hash('AdminTest@123', PASSWORD_BCRYPT), 'No-Mod Admin']);
$nm = __DIR__ . '/nm.txt'; @unlink($nm);
api('POST', "$BASE/api/auth/login.php", $nm, ['email' => 'nomod-admin@evcharge.com', 'password' => 'AdminTest@123', 'user_type' => 'admin']);
csrfFor($BASE, $nm, 'public/dashboard/admin.php', true); // prime the fresh admin session's token before the warn attempt
$nmr = api('POST', "$BASE/api/reviews.php", $nm, ['action' => 'warn', 'owner_id' => $ownId, 'reason' => 'Suite: should be blocked']);
rep('19k. non-moderator admin blocked', ($nmr['status'] ?? '') === 'error' && strpos($nmr['message'] ?? '', 'cannot moderate') !== false, json_encode($nmr));
q($db, "DELETE FROM admins WHERE email='nomod-admin@evcharge.com'"); @unlink($nm);
q($db, "UPDATE owners SET warning_count=0 WHERE id=?", [$ownId]);
q($db, "DELETE FROM activity_logs WHERE action IN ('review_flagged','review_dismissed','owner_warning') AND details LIKE '%Suite%'");

$r49 = api('POST', "$BASE/api/support.php", $dc, ['action' => 'create', 'category' => 'booking', 'subject' => 'Integration ticket A', 'message' => 'Driver needs help']);
$tidA = intval($r49['data']['ticket_id'] ?? 0);
rep('49. driver creates ticket', ($r49['status'] ?? '') === 'success' && $tidA > 0, json_encode($r49));

$r50 = api('POST', "$BASE/api/support.php", $oc, ['action' => 'create', 'category' => 'payment', 'subject' => 'Integration ticket B', 'message' => 'Owner payout question']);
$tidO = intval($r50['data']['ticket_id'] ?? 0);
rep('50. owner creates ticket', ($r50['status'] ?? '') === 'success' && $tidO > 0, json_encode($r50));

$listD = api('GET', "$BASE/api/support.php", $dc)['data']['tickets'];
$listO = api('GET', "$BASE/api/support.php", $oc)['data']['tickets'];
$idsD = array_column($listD, 'id'); $idsO = array_column($listO, 'id');
$t51a = in_array($tidA, $idsD) && !in_array($tidO, $idsD);
rep('51a. driver list scoped', $t51a, json_encode($idsD));
$t51b = in_array($tidO, $idsO) && !in_array($tidA, $idsO);
rep('51b. owner list scoped', $t51b, json_encode($idsO));

$r52 = api('GET', "$BASE/api/support.php?id=$tidA", $oc);
rep('52. cross-id read rejected', ($r52['status'] ?? '') === 'error' && strpos($r52['message'] ?? '', 'not found') !== false, json_encode($r52));

$r53 = api('POST', "$BASE/api/support.php", $oc, ['action' => 'reply', 'ticket_id' => $tidA, 'reply' => 'hijack']);
rep('53. cross-user reply rejected', ($r53['status'] ?? '') === 'error' && strpos($r53['message'] ?? '', 'Only admins') !== false, json_encode($r53));

// 54 missing CSRF: unprimed fresh driver session
$dz = __DIR__ . '/dz.txt'; @unlink($dz);
api('POST', "$BASE/api/auth/login.php", $dz, ['email' => 'driver1@example.com', 'password' => 'Test@123', 'user_type' => 'driver']);
$r54raw = tpost("$BASE/api/support.php", ['action' => 'create', 'subject' => 'x', 'message' => 'y'], null, $dz);
rep('54. missing CSRF -> distinct 403', $r54raw[0] === 403 && strpos($r54raw[1]['message'] ?? '', 'Invalid security token') !== false, 'http=' . $r54raw[0]);
@unlink($dz);

$listA = api('GET', "$BASE/api/support.php", $ac)['data']['tickets'];
$idsA = array_column($listA, 'id');
$names = array_column($listA, 'submitter_name');
$t55a = in_array($tidA, $idsA) && in_array($tidO, $idsA);
rep('55a. admin sees ALL tickets', $t55a, json_encode($idsA));

$r55 = api('POST', "$BASE/api/support.php", $ac, ['action' => 'reply', 'ticket_id' => $tidA, 'reply' => 'Fixed the booking issue.']);
$row55 = q($db, "SELECT admin_reply, status FROM support_tickets WHERE id = ?", [$tidA])[0];
$notif = (int)q($db, "SELECT COUNT(*) c FROM activity_logs WHERE resource_type='support_ticket' AND resource_id=? AND user_id IS NOT NULL", [$tidA])[0]['c'];
rep('55b. reply stored + status advanced + bell row', ($r55['status'] ?? '') === 'success' && $row55['status'] === 'in_progress' && $row55['admin_reply'] !== null && $notif >= 1,
    json_encode($row55) . " notif=$notif");

$r56 = api('POST', "$BASE/api/support.php", $ac, ['action' => 'set_status', 'ticket_id' => $tidA, 'status' => 'resolved']);
$row56 = q($db, "SELECT status FROM support_tickets WHERE id = ?", [$tidA])[0];
rep('56. set_status resolved', ($r56['status'] ?? '') === 'success' && $row56['status'] === 'resolved', json_encode($row56));

$guestJar = __DIR__ . '/gj.txt'; @unlink($guestJar);
$r57 = tpost("$BASE/api/support.php", ['action' => 'create', 'subject' => 'anon', 'message' => 'anon'], null, $guestJar);
rep('57. guest POST redirected (no session)', $r57[0] === 302, 'http=' . $r57[0]);
@unlink($guestJar);

$db->prepare("DELETE FROM support_tickets")->execute();
$db->prepare("DELETE FROM admins WHERE email = ?")->execute(['supporttest-admin@evcharge.com']);

// ===== 58-61: STATION APPROVAL/REJECTION OWNER NOTIFICATIONS =====
$adminHash3 = password_hash('AdminTest@123', PASSWORD_BCRYPT);
$db->prepare("INSERT INTO admins (email, password, name, role) VALUES (?, ?, ?, 'super_admin')
              ON DUPLICATE KEY UPDATE password = VALUES(password)")
   ->execute(['supporttest-admin@evcharge.com', $adminHash3, 'Support Test Admin']);
$ac2 = __DIR__ . '/ac2.txt';
@unlink($ac2);
api('POST', "$BASE/api/auth/login.php", $ac2, ['email' => 'supporttest-admin@evcharge.com', 'password' => 'AdminTest@123', 'user_type' => 'admin']);
csrfFor($BASE, $ac2, 'public/dashboard/admin.php', true);

$db->prepare("INSERT INTO stations (owner_id, name, latitude, longitude, address, city, num_chargers, approval_status)
              VALUES (1, 'Notif Test Station A', 27.70, 85.32, 'Kathmandu', 'Kathmandu', 1, 'pending')")->execute();
$sidA = intval($db->lastInsertId());
$db->prepare("INSERT INTO stations (owner_id, name, latitude, longitude, address, city, num_chargers, approval_status)
              VALUES (1, 'Notif Test Station B', 27.71, 85.33, 'Kathmandu', 'Kathmandu', 1, 'pending')")->execute();
$sidB = intval($db->lastInsertId());

$r58 = api('POST', "$BASE/api/stations.php?action=approve&id=$sidA", $ac2);
$row58 = q($db, "SELECT owner_id, action, details FROM activity_logs WHERE resource_type='station' AND resource_id=? ORDER BY id DESC LIMIT 1", [$sidA])[0];
rep('58. approve notifies owner', ($r58['status'] ?? '') === 'success'
    && $row58['owner_id'] == 1
    && $row58['action'] === 'station_approved'
    && strpos($row58['details'], 'Notif Test Station A') !== false
    && strpos($row58['details'], 'approved and is now live') !== false,
    json_encode($row58));

$no59 = api('GET', "$BASE/api/notifications.php", $oc);
$has59 = false;
foreach (($no59['data']['items'] ?? []) as $it) {
    if (($it['action'] ?? '') === 'station_approved' && strpos($it['details'] ?? '', 'Notif Test Station A') !== false) { $has59 = true; }
}
rep('59. owner bell lists approval', $has59, 'unread=' . ($no59['data']['unread_count'] ?? '?'));

$r60 = api('POST', "$BASE/api/stations.php?action=reject&id=$sidB", $ac2, ['reason' => 'Incomplete charger details']);
$row60 = q($db, "SELECT owner_id, action, details FROM activity_logs WHERE resource_type='station' AND resource_id=? ORDER BY id DESC LIMIT 1", [$sidB])[0];
rep('60. reject notifies owner with reason', ($r60['status'] ?? '') === 'success'
    && $row60['owner_id'] == 1
    && $row60['action'] === 'station_rejected'
    && strpos($row60['details'], 'Notif Test Station B') !== false
    && strpos($row60['details'], 'was rejected') !== false
    && strpos($row60['details'], 'Incomplete charger details') !== false,
    json_encode($row60));

// 61: driver isolation — station events are scoped out of the driver bell entirely
$noDrv = api('GET', "$BASE/api/notifications.php", $dc);
$drvJson = json_encode($noDrv['data']['items'] ?? []);
rep('61. driver isolation (scoped out)', strpos($drvJson, 'station_approved') === false && strpos($drvJson, 'station_rejected') === false && strpos($drvJson, 'Notif Test Station') === false, substr($drvJson, 0, 140));

// cleanup synthetic stations + their activity rows (incl. any strays from earlier runs)
$db->prepare("DELETE FROM stations WHERE name LIKE 'Notif Test Station%'")->execute();
$db->prepare("DELETE FROM activity_logs WHERE resource_type='station' AND (resource_id IN (?, ?) OR details LIKE '%Notif Test Station%')")->execute([$sidA, $sidB]);

// ===== 62-69: GOOGLE SIGN-UP PROVISIONAL ACCOUNT FLOW =====
// Scope honesty (R7): the live OAuth leg (browser One Tap -> Google tokeninfo
// verification of a real ID token) cannot be exercised offline without either
// network interception or a test-only bypass seam (rejected as a security risk).
// Everything around that leg IS covered below: migration defaults, the shared
// dashboard gate function, the completion-API guards (CSRF / role / state), and
// byte-for-byte the SQL shapes the new provisioning/completion branches run.
$u62 = $db->query("SELECT COUNT(*) c FROM users WHERE profile_complete = 0")->fetch()['c'];
$o62 = $db->query("SELECT COUNT(*) c FROM owners WHERE profile_complete = 0")->fetch()['c'];
// 2026-08-29: the fabricated-data cleanup intentionally set profile_complete=0 on
// driver id=17 and owner id=4 (operator's own accounts, routed through the
// completion flow on next login) - so the zero-flip invariant is now an upper bound.
rep('62. ALTER defaults: no mass-flip of pre-existing rows', $u62 <= 1 && $o62 <= 1, "users_incomplete=$u62 owners_incomplete=$o62");

// 63/64: gate function probed in a subprocess - requireProfileComplete() exits
// mid-script when it trips, so the REACHED marker proves continued execution.
$probeFile = __DIR__ . '/_gate_probe.tmp.php';
$probeBase = '<?php
require_once __DIR__ . "/../app/helpers/Auth.php";
$_SESSION["user_id"] = 999001; $_SESSION["user_type"] = "driver";
$_SESSION["login_time"] = time(); $_SESSION["user_agent"] = "";
__FLAG__
Auth::requireProfileComplete();
echo "REACHED";
';
file_put_contents($probeFile, str_replace('__FLAG__', '$_SESSION["profile_complete"] = false;', $probeBase));
$out63 = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probeFile) . ' 2>&1', $out63);
file_put_contents($probeFile, str_replace('__FLAG__', '// unset = password-login shape', $probeBase));
$out64 = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probeFile) . ' 2>&1', $out64);
unlink($probeFile);
rep('63. gate blocks flagged-incomplete session', implode('', $out63) === '', 'out="' . implode(' ', $out63) . '"');
rep('64. gate leaves normal sessions untouched', strpos(implode('', $out64), 'REACHED') !== false, 'out="' . implode(' ', $out64) . '"');

// Fresh authenticated jars for the completion-API guard matrix.
$gg = __DIR__ . '/gs_driver.txt'; @unlink($gg);
api('POST', "$BASE/api/auth/login.php", $gg, ['email' => 'driver1@example.com', 'password' => 'Test@123', 'user_type' => 'driver']);
csrfFor($BASE, $gg, 'public/dashboard/driver.php', true);
// Unprimed sibling jar for the SAME seed account: separate cookie jar = separate
// server-side session (profile_complete unset), but api() attaches no CSRF token
// because csrfFor() was never called against this jar.
$gn = __DIR__ . '/gs_noc.txt'; @unlink($gn);
api('POST', "$BASE/api/auth/login.php", $gn, ['email' => 'driver1@example.com', 'password' => 'Test@123', 'user_type' => 'driver']);
$ga2 = __DIR__ . '/gs_admin.txt'; @unlink($ga2);
api('POST', "$BASE/api/auth/login.php", $ga2, ['email' => 'supporttest-admin@evcharge.com', 'password' => 'AdminTest@123', 'user_type' => 'admin']);

$r65 = tpost("$BASE/api/auth/google.php", ['action' => 'complete_profile', 'name' => 'Test Driver One', 'car_model' => 'Nexon EV', 'battery_capacity' => 40], null, $gn);
rep('65. completion without CSRF -> distinct 403', ($r65[0] ?? 0) === 403
    && strpos($r65[1]['message'] ?? '', 'security token') !== false, json_encode($r65));

$admTok = csrfFor($BASE, $ga2, 'public/dashboard/admin.php', true);
$r66 = tpost("$BASE/api/auth/google.php", ['action' => 'complete_profile', 'name' => 'Admin Person'], $admTok, $ga2);
rep('66. completion refused for admin sessions', ($r66[0] ?? 0) === 403
    && strpos($r66[1]['message'] ?? '', 'driver and owner') !== false, json_encode($r66));

$r67 = api('POST', "$BASE/api/auth/google.php", $gg, ['action' => 'complete_profile', 'name' => 'Test Driver One', 'car_model' => 'Nexon EV', 'battery_capacity' => 40]);
rep('67. already-complete session cannot rewrite via this endpoint (409)', ($r67['status'] ?? '') === 'error'
    && strpos($r67['message'] ?? '', 'already completed') !== false, json_encode($r67));

// 68: driver branch SQL shapes, replayed exactly as google.php issues them.
$db->prepare("INSERT INTO users (email, password, name, email_verified, profile_complete)
              VALUES ('gprov-driver@test.local', 'x', 'Prov Driver', TRUE, FALSE)")->execute();
$p68 = intval($db->lastInsertId());
$pre68 = q($db, "SELECT car_model, car_full_capacity_kwh, phone, profile_complete FROM users WHERE id=?", [$p68])[0];
$db->prepare("UPDATE users SET name=?, car_model=?, car_full_capacity_kwh=?, profile_complete=TRUE WHERE id=?")
   ->execute(['Prov Driver Final', 'Tata Nexon EV Max', 45.50, $p68]);
$post68 = q($db, "SELECT name, car_model, car_full_capacity_kwh, profile_complete FROM users WHERE id=?", [$p68])[0];
rep('68. driver: sparse provisional row -> completion UPDATE flips cleanly',
    is_null($pre68['car_model']) && is_null($pre68['phone']) && $pre68['profile_complete'] == 0
    && $post68['car_model'] === 'Tata Nexon EV Max' && floatval($post68['car_full_capacity_kwh']) === 45.5 && $post68['profile_complete'] == 1,
    json_encode([$pre68, $post68]));
$db->prepare("DELETE FROM users WHERE id=?")->execute([$p68]);

// 69: owner variant - NOT NULL company satisfied with '' provisionally.
$db->prepare("INSERT INTO owners (email, password, name, company_name, email_verified, approval_status, profile_complete)
              VALUES ('gprov-owner@test.local', 'x', 'Prov Owner', '', TRUE, 'approved', FALSE)")->execute();
$p69 = intval($db->lastInsertId());
$pre69 = q($db, "SELECT company_name, bank_account_number, profile_complete FROM owners WHERE id=?", [$p69])[0];
$db->prepare("UPDATE owners SET name=?, company_name=?, bank_account_number=?, profile_complete=TRUE WHERE id=?")
   ->execute(['Prov Owner Final', 'Himalayan Charge Co', '98765432101234', $p69]);
$post69 = q($db, "SELECT company_name, bank_account_number, profile_complete FROM owners WHERE id=?", [$p69])[0];
rep('69. owner: provisional empty-string company -> completion UPDATE flips cleanly',
    $pre69['company_name'] === '' && is_null($pre69['bank_account_number']) && $pre69['profile_complete'] == 0
    && $post69['company_name'] === 'Himalayan Charge Co' && $post69['bank_account_number'] === '98765432101234' && $post69['profile_complete'] == 1,
    json_encode([$pre69, $post69]));
$db->prepare("DELETE FROM owners WHERE id=?")->execute([$p69]);

// Teardown: empty throttle + support-notification rows so later suite runs and manual logins aren't blocked
q($db, "DELETE FROM login_attempts");
q($db, "DELETE FROM activity_logs WHERE resource_type = 'support_ticket'");
$left = (int)q($db, "SELECT COUNT(*) c FROM login_attempts")[0]['c'];
rep('teardown. login_attempts emptied for next run', $left === 0, "rows_left=$left");

echo "DONE\n";
