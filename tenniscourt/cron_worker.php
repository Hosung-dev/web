<?php
header("Content-Type: text/html; charset=UTF-8");
date_default_timezone_set('Asia/Seoul'); 

define('GMAIL_USER', 'urpinetrees@gmail.com'); 
define('GMAIL_APP_PASS', 'bcavvpyxufknrirg'); 

function write_log($message, $type = 'INFO') {
    $time = date('Y-m-d H:i:s');
    $color = '#2d3748'; 
    if ($type === 'SUCCESS') $color = '#48bb78'; 
    if ($type === 'WARNING') $color = '#dd6b20'; 
    if ($type === 'ERROR')   $color = '#e53e3e'; 
    if ($type === 'DEBUG')   $color = '#3182ce'; 
    
    echo "<div style='font-family:Consolas, Monaco, monospace; padding:4px 8px; margin:2px 0; border-left:4px solid {$color}; background-color:#f7fafc; color:{$color};'>";
    echo "<strong>[{$time}] [{$type}]</strong> " . htmlspecialchars($message);
    echo "</div>";
    @ob_flush();
    @flush();
}

function send_gmail_direct($to, $subject, $body) {
    write_log("구글 SMTP 보안 서버 연결 시도...", "DEBUG");
    $socket = @fsockopen("ssl://smtp.gmail.com", 465, $errno, $errstr, 10);
    if (!$socket) { write_log("구글 465 포트 연결 실패. (에러: $errstr)", "ERROR"); return false; }
    
    fgets($socket, 512);
    fwrite($socket, "EHLO localhost\r\n");
    while($line = fgets($socket, 512)) { if(substr($line, 3, 1) == " ") break; }
    
    fwrite($socket, "AUTH LOGIN\r\n"); fgets($socket, 512);
    fwrite($socket, base64_encode(GMAIL_USER) . "\r\n"); fgets($socket, 512);
    fwrite($socket, base64_encode(GMAIL_APP_PASS) . "\r\n"); $response = fgets($socket, 512);
    
    if (strpos($response, '235') === false) { write_log("구글 인증 실패!", "ERROR"); fclose($socket); return false; }
    write_log("구글 SMTP 인증 성공!", "SUCCESS");
    
    fwrite($socket, "MAIL FROM:<" . GMAIL_USER . ">\r\n"); fgets($socket, 512);
    fwrite($socket, "RCPT TO:<" . $to . ">\r\n"); fgets($socket, 512);
    fwrite($socket, "DATA\r\n"); fgets($socket, 512);
    
    $headers = "From: =?UTF-8?B?" . base64_encode("테니스 예약 알림이") . "?= <" . GMAIL_USER . ">\r\n";
    $headers .= "To: <" . $to . ">\r\n";
    $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
    
    fwrite($socket, $headers . $body . "\r\n.\r\n"); $response = fgets($socket, 512);
    fwrite($socket, "QUIT\r\n"); fclose($socket);
    return (strpos($response, '250') !== false);
}

write_log("==================================================", "INFO");
write_log("통합 매칭 전코트 스캔 크론 가동을 시작합니다.", "INFO");

$db_file = __DIR__ . '/notifications_db.json';
if (!file_exists($db_file)) { write_log("DB 파일이 존재하지 않습니다.", "WARNING"); exit(); }

$db = json_decode(file_get_contents($db_file), true);
if (json_last_error() !== JSON_ERROR_NONE) { write_log("DB 파싱 실패", "ERROR"); exit(); }

$updated = false;

// 각 경기장별 전체 탐색용 마스터 배열 명단
$solter_all = [
    "1번 코트" => ["code"=>18, "part"=>"04"], "2번 코트" => ["code"=>19, "part"=>"04"],
    "3번 코트" => ["code"=>20, "part"=>"04"], "4번 코트" => ["code"=>21, "part"=>"04"],
    "5번 코트" => ["code"=>22, "part"=>"04"], "6번 코트" => ["code"=>23, "part"=>"04"],
    "7번 코트" => ["code"=>24, "part"=>"04"], "8번 코트" => ["code"=>25, "part"=>"04"],
    "A코트" => ["code"=>34, "part"=>"13"], "B코트" => ["code"=>35, "part"=>"13"],
    "C코트" => ["code"=>36, "part"=>"13"], "D코트" => ["code"=>37, "part"=>"13"]
];

$seoam_all = ["1번 코트" => "A관", "2번 코트" => "B관", "3번 코트" => "C관"];

$parcos_all = [
    "1번 코트" => "A관", "2번 코트" => "B관", "3번 코트" => "C관", "4번 코트" => "D관",
    "5번 코트" => "E관", "6번 코트" => "F관", "7번 코트" => "G관", "8번 코트" => "H관"
];

foreach ($db as $index => &$job) {
    $job_no = $index + 1;
    write_log("--------------------------------------------------", "INFO");
    write_log("알림 작업 #{$job_no} 통합 검증 시작 (ID: {$job['id']})", "INFO");

    if ($job['status'] !== 'ACTIVE') {
        write_log("작업 #{$job_no} 상태가 ACTIVE가 아니므로 패스합니다.", "WARNING");
        continue;
    }
    
    $rawDate = str_replace(['-', ' '], '', $job['date']); 
    $target_court_found = null; // 예약 빈자리 매칭된 코트 이름 저장용 변수
    
    // [분기 1: 솔터테니스장 전체 스캔]
    if ($job['site'] === '솔터') {
        foreach ($solter_all as $court_display_name => $cMeta) {
            $parsed_slots = [];
            $url = "https://yeyak.guc.or.kr/rest/facilities/place_time_state_list?company_code=GIMPO02&part_code={$cMeta['part']}&place_code={$cMeta['code']}&base_date={$rawDate}&rent_type=1001";

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
            $jsonText = curl_exec($ch); curl_close($ch);

            $jsonData = json_decode($jsonText, true);
            if (is_array($jsonData)) {
                foreach ($jsonData as $item) {
                    if (isset($item['start_time'])) {
                        $parsed_slots[$item['start_time']] = ($item['use_yn'] === 'N') ? 'on' : 'off';
                    }
                }
            }

            // 슬롯 조건 확인
            $time1_ok = (isset($parsed_slots[$job['time1']]) && $parsed_slots[$job['time1']] === 'on');
            $time2_ok = ($job['duration'] === '2') ? (isset($parsed_slots[$job['time2']]) && $parsed_slots[$job['time2']] === 'on') : true;

            if ($time1_ok && $time2_ok) {
                $target_court_found = $court_display_name;
                break; // 하나라도 찾으면 즉시 해당 코트로 확정 후 루프 종료
            }
        }
        
    // [분기 2: 서암 및 파르코스 전체 스캔]
    } else {
        $scan_list = ($job['site'] === '파르코스') ? $parcos_all : $seoam_all;
        $url_base = ($job['site'] === '파르코스') ? "http://www.gimposports.or.kr/skin/orders/timeBoard4.php" : "http://www.gimposports.or.kr/skin/orders/timeBoard.php";
        $sTeb = ($job['site'] === '파르코스') ? 'g' : 'b';

        foreach ($scan_list as $court_display_name => $sRoom) {
            $parsed_slots = [];
            $post_data = ['sTeb' => $sTeb, 'sRoom' => $sRoom, 'orderDate' => $rawDate, 'settingTimeSet' => '2'];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url_base); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            $htmlText = curl_exec($ch); curl_close($ch);

            if (!$htmlText || strpos($htmlText, "프록시 연결 에러") !== false) continue;

            $regex = '/<label\s+[^>]*class=["\']([^"\']*)["\']\s+[^>]*data=["\']([^"\']*)["\']|<label\s+[^>]*data=["\']([^"\']*)["\']\s+[^>]*class=["\']([^"\']*)["\']/i';
            if (preg_match_all($regex, $htmlText, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $cls = !empty($match[1]) ? $match[1] : (isset($match[4]) ? $match[4] : '');
                    $tm = !empty($match[2]) ? $match[2] : (isset($match[3]) ? $match[3] : '');
                    if ($tm) {
                        $tm = trim(preg_replace('/[\s\x{00a0}]/u', '', $tm));
                        $parsed_slots[$tm] = (strpos($cls, 'on') !== false) ? 'on' : 'off';
                    }
                }
            }

            // 슬롯 조건 확인
            $time1_ok = (isset($parsed_slots[$job['time1']]) && $parsed_slots[$job['time1']] === 'on');
            $time2_ok = ($job['duration'] === '2') ? (isset($parsed_slots[$job['time2']]) && $parsed_slots[$job['time2']] === 'on') : true;

            if ($time1_ok && $time2_ok) {
                $target_court_found = $court_display_name;
                break; 
            }
        }
    }

    // 최종 이메일 발송 처리
    if ($target_court_found !== null) {
        write_log("🎉 빈자리 매칭 성공! 발견 코트: [{$target_court_found}] 메일을 발송합니다.", "SUCCESS");
        
        $subject = "[🎾테니스알림] {$job['site']} [{$target_court_found}] 예약 가능 자리가 나왔습니다!";
        $time_desc = $job['duration'] === '2' ? "{$job['time1']} ~ {$job['time2']}" : "{$job['time1']}";
        $formatted_date = substr($rawDate,0,4)."-".substr($rawDate,4,2)."-".substr($rawDate,6,2);
        
        $body = "신청하신 테니스 경기장 전체 코트를 스캔한 결과, 예약 가능한 빈자리가 발견되었습니다.\n\n";
        $body .= "■ 경기장명: {$job['site']}\n";
        $body .= "■ 확정코트: {$target_court_found} (전체 자동 스캔)\n";
        $body .= "■ 예약일자: {$formatted_date}\n";
        $body .= "■ 희망시간: {$time_desc}\n\n";
        $body .= "지금 즉시 통합 예약시스템에 접속하셔서 확정 예약을 진행하세요!\n";
        
        if (send_gmail_direct($job['email'], $subject, $body)) {
            $job['status'] = 'SENT';
            $updated = true;
            write_log("구글 서버가 메일 수락 완료. DB 상태를 SENT로 업데이트합니다.", "SUCCESS");
        } else {
            write_log("메일 발송 최종 실패.", "ERROR");
        }
    } else {
        write_log("[{$job['site']}] 희망하시는 시간대에 예약 가능한 코트가 전혀 없습니다. 계속 감시합니다.", "INFO");
    }
}

if ($updated) {
    file_put_contents($db_file, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
write_log("==================================================", "INFO");
?>