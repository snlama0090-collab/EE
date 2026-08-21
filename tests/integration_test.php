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
echo "DONE\n";
