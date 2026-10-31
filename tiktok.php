<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$username = trim((string)($_GET['username'] ?? ''));
$username = preg_replace('/^@+/', '', $username);
$username = preg_replace('/[^A-Za-z0-9._-]/', '', $username);
if ($username === '') { http_response_code(400); echo json_encode(['status'=>false,'message'=>'Thiếu username'], JSON_UNESCAPED_UNICODE); exit; }
$api = 'https://socialmediainfo-nu.vercel.app/riduan/tiktok?username=' . rawurlencode($username);
$ch = curl_init($api);
curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>25,CURLOPT_HTTPHEADER=>['Accept: application/json','User-Agent: Mozilla/5.0 (Android)']]);
$body = curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch);
if($body===false || $body===''){http_response_code(502);echo json_encode(['status'=>false,'message'=>'Không kết nối được API nguồn'],JSON_UNESCAPED_UNICODE);exit;}
$data=json_decode($body,true);
if(!is_array($data)){http_response_code(502);echo json_encode(['status'=>false,'message'=>'API nguồn không trả về JSON hợp lệ.','upstream_status'=>$status],JSON_UNESCAPED_UNICODE);exit;}
http_response_code($status ?: 200); echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
