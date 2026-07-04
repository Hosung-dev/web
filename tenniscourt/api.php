<?php
header("Content-Type: application/json; charset=UTF-8");

$db_file = __DIR__ . '/notifications_db.json';

if (!file_exists($db_file)) {
    file_put_contents($db_file, json_encode([], JSON_PRETTY_PRINT));
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

// 1. 목록 조회
if ($action === 'list') {
    $data = json_decode(file_get_contents($db_file), true);
    if (!is_array($data)) $data = [];
    echo json_encode(array_reverse($data), JSON_UNESCAPED_UNICODE);
    exit();
}

// 2. 신규 알림 등록
if ($action === 'create') {
    $input = json_decode(file_get_contents("php://input"), true);
    
    if (empty($input['site']) || empty($input['date']) || empty($input['email'])) {
        echo json_encode(['success' => false, 'message' => '필수 입력 항목이 누락되었습니다.']);
        exit();
    }
    
    $data = json_decode(file_get_contents($db_file), true);
    if (!is_array($data)) $data = [];
    
    $new_job = [
        'id' => uniqid('job_', true),
        'site' => $input['site'],
        'court' => '전체', 
        'date' => $input['date'],
        'time1' => $input['time1'],
        'time2' => isset($input['time2']) ? $input['time2'] : '',
        'duration' => $input['duration'],
        'email' => $input['email'],
        'status' => 'ACTIVE',
        'created_at' => date('Y-m-d H:i:s')
    ];
    
    $data[] = $new_job;
    
    if (file_put_contents($db_file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'DB 저장에 실패했습니다.']);
    }
    exit();
}

// 3. 알림 삭제
if ($action === 'delete') {
    $input = json_decode(file_get_contents("php://input"), true);
    if (empty($input['id'])) {
        echo json_encode(['success' => false, 'message' => '삭제할 ID가 지정되지 않았습니다.']);
        exit();
    }
    $data = json_decode(file_get_contents($db_file), true);
    if (!is_array($data)) $data = [];
    $filtered_data = [];
    foreach ($data as $job) {
        if ($job['id'] === $input['id']) continue;
        $filtered_data[] = $job;
    }
    if (file_put_contents($db_file, json_encode(array_values($filtered_data), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'DB 업데이트에 실패했습니다.']);
    }
    exit();
}

// 4. 🌟 [수정 추가] 알림 상태 초기화 및 재가동 (REACTIVATE)
if ($action === 'reactivate') {
    $input = json_decode(file_get_contents("php://input"), true);
    if (empty($input['id'])) {
        echo json_encode(['success' => false, 'message' => '대상 ID가 지정되지 않았습니다.']);
        exit();
    }
    
    $data = json_decode(file_get_contents($db_file), true);
    if (!is_array($data)) $data = [];
    
    $found = false;
    foreach ($data as &$job) {
        if ($job['id'] === $input['id']) {
            $job['status'] = 'ACTIVE'; // 상태를 감시중으로 원복
            $found = true;
            break;
        }
    }
    
    if (!$found) {
        echo json_encode(['success' => false, 'message' => '해당 알림 데이터를 찾을 수 없습니다.']);
        exit();
    }
    
    if (file_put_contents($db_file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'DB 업데이트에 실패했습니다.']);
    }
    exit();
}

echo json_encode(['success' => false, 'message' => '잘못된 액션 요청입니다.']);
?>