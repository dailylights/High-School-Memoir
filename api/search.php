<?php
require 'db.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// CSRF protection for POST requests
csrfProtection();

// 获取当前登录用户ID
function getCurrentUserId() {
    return isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
}

// 记录搜索历史
function recordSearchHistory($conn, $userId, $keyword, $searchType, $resultCount) {
    if (empty($keyword)) return;
    
    // 记录用户搜索历史
    if ($userId > 0) {
        $stmt = $conn->prepare("INSERT INTO search_history (user_id, keyword, search_type, result_count) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("issi", $userId, $keyword, $searchType, $resultCount);
        $stmt->execute();
    }
    
    // 更新热门搜索词
    $keyword = trim($keyword);
    if (strlen($keyword) >= 2) {
        $checkStmt = $conn->prepare("SELECT id, search_count FROM hot_searches WHERE keyword = ?");
        $checkStmt->bind_param("s", $keyword);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $newCount = $row['search_count'] + 1;
            $updateStmt = $conn->prepare("UPDATE hot_searches SET search_count = ?, last_search_at = NOW() WHERE id = ?");
            $updateStmt->bind_param("ii", $newCount, $row['id']);
            $updateStmt->execute();
        } else {
            $insertStmt = $conn->prepare("INSERT INTO hot_searches (keyword, search_count) VALUES (?, 1)");
            $insertStmt->bind_param("s", $keyword);
            $insertStmt->execute();
        }
    }
}

// 获取热门搜索词
function getHotSearches($conn, $limit = 10) {
    $stmt = $conn->prepare("SELECT keyword, search_count FROM hot_searches ORDER BY search_count DESC, last_search_at DESC LIMIT ?");
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $hotSearches = [];
    while ($row = $result->fetch_assoc()) {
        $hotSearches[] = $row;
    }
    return $hotSearches;
}

// 获取用户搜索历史
function getUserSearchHistory($conn, $userId, $limit = 20) {
    if ($userId <= 0) return [];
    
    $stmt = $conn->prepare("SELECT DISTINCT keyword, search_type, created_at FROM search_history WHERE user_id = ? ORDER BY created_at DESC LIMIT ?");
    $stmt->bind_param("ii", $userId, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $history = [];
    while ($row = $result->fetch_assoc()) {
        $history[] = $row;
    }
    return $history;
}

// 删除用户搜索历史
function clearUserSearchHistory($conn, $userId) {
    if ($userId <= 0) return false;
    
    $stmt = $conn->prepare("DELETE FROM search_history WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    return $stmt->execute();
}

// 高级搜索 - 回忆录
function searchMemoirs($conn, $params, $currentUserId) {
    $keyword = trim($params['keyword'] ?? '');
    $authorId = intval($params['author_id'] ?? 0);
    $classId = intval($params['class_id'] ?? 0);
    $graduationId = intval($params['graduation_id'] ?? 0);
    $topicId = intval($params['topic_id'] ?? 0);
    $dateFrom = $params['date_from'] ?? '';
    $dateTo = $params['date_to'] ?? '';
    $hasMedia = isset($params['has_media']) ? intval($params['has_media']) : -1;
    $page = max(1, intval($params['page'] ?? 1));
    $limit = min(50, max(1, intval($params['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;
    
    $where = ["m.id > 0"];
    $types = "";
    $values = [];
    
    // 关键词搜索
    if (!empty($keyword)) {
        $where[] = "(m.content LIKE ? OR u.name LIKE ? OR t.name LIKE ?)";
        $searchTerm = "%" . $keyword . "%";
        $types .= "sss";
        $values[] = $searchTerm;
        $values[] = $searchTerm;
        $values[] = $searchTerm;
    }
    
    // 作者筛选
    if ($authorId > 0) {
        $where[] = "m.user_id = ?";
        $types .= "i";
        $values[] = $authorId;
    }
    
    // 班级筛选
    if ($classId > 0) {
        $where[] = "m.class_id = ?";
        $types .= "i";
        $values[] = $classId;
    }
    
    // 届别筛选
    if ($graduationId > 0) {
        $where[] = "c.graduation_id = ?";
        $types .= "i";
        $values[] = $graduationId;
    }
    
    // 话题筛选
    if ($topicId > 0) {
        $where[] = "m.topic_id = ?";
        $types .= "i";
        $values[] = $topicId;
    }
    
    // 时间范围筛选
    if (!empty($dateFrom)) {
        $where[] = "m.created_at >= ?";
        $types .= "s";
        $values[] = $dateFrom . " 00:00:00";
    }
    
    if (!empty($dateTo)) {
        $where[] = "m.created_at <= ?";
        $types .= "s";
        $values[] = $dateTo . " 23:59:59";
    }
    
    // 是否有媒体筛选
    if ($hasMedia === 1) {
        $where[] = "(m.images IS NOT NULL AND m.images != '' AND m.images != '[]')";
    } elseif ($hasMedia === 0) {
        $where[] = "(m.images IS NULL OR m.images = '' OR m.images = '[]')";
    }
    
    $whereClause = implode(" AND ", $where);
    
    // 统计总数
    $countSql = "SELECT COUNT(*) as total FROM memoirs m 
                 JOIN users u ON m.user_id = u.id 
                 LEFT JOIN classes c ON m.class_id = c.id
                 LEFT JOIN topics t ON m.topic_id = t.id
                 WHERE $whereClause";
    
    $countStmt = $conn->prepare($countSql);
    if (!empty($values)) {
        $countStmt->bind_param($types, ...$values);
    }
    $countStmt->execute();
    $totalResult = $countStmt->get_result();
    $totalRows = $totalResult->fetch_assoc()['total'];
    $totalPages = ceil($totalRows / $limit);
    
    // 获取数据
    $sql = "SELECT m.*, u.name as author_name, u.class as author_class, u.avatar as author_avatar, 
                   t.name as topic_name, c.name as class_name,
                   (SELECT COUNT(*) FROM likes l WHERE l.memoir_id = m.id) as likes_count,
                   (SELECT COUNT(*) FROM comments c2 WHERE c2.memoir_id = m.id) as comments_count,
                   (SELECT COUNT(*) FROM likes l2 WHERE l2.memoir_id = m.id AND l2.user_id = ?) as is_liked
            FROM memoirs m 
            JOIN users u ON m.user_id = u.id 
            LEFT JOIN classes c ON m.class_id = c.id
            LEFT JOIN topics t ON m.topic_id = t.id
            WHERE $whereClause
            ORDER BY m.created_at DESC
            LIMIT ? OFFSET ?";
    
    $finalValues = array_merge([$currentUserId], $values, [$limit, $offset]);
    $finalTypes = "i" . $types . "ii";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($finalTypes, ...$finalValues);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $memoirs = [];
    while ($row = $result->fetch_assoc()) {
        if (!empty($row['images'])) {
            $row['images_array'] = json_decode($row['images'], true) ?? [];
        } else {
            $row['images_array'] = [];
        }
        $memoirs[] = $row;
    }
    
    return [
        'items' => $memoirs,
        'total' => $totalRows,
        'page' => $page,
        'limit' => $limit,
        'total_pages' => $totalPages
    ];
}

// 搜索用户
function searchUsers($conn, $keyword, $page = 1, $limit = 20) {
    $keyword = trim($keyword);
    if (empty($keyword)) {
        return ['items' => [], 'total' => 0, 'page' => 1, 'limit' => $limit, 'total_pages' => 0];
    }
    
    $offset = ($page - 1) * $limit;
    $searchTerm = "%" . $keyword . "%";
    
    // 统计
    $countSql = "SELECT COUNT(*) as total FROM users WHERE name LIKE ? OR username LIKE ? OR class LIKE ?";
    $countStmt = $conn->prepare($countSql);
    $countStmt->bind_param("sss", $searchTerm, $searchTerm, $searchTerm);
    $countStmt->execute();
    $totalResult = $countStmt->get_result();
    $totalRows = $totalResult->fetch_assoc()['total'];
    $totalPages = ceil($totalRows / $limit);
    
    // 数据
    $sql = "SELECT u.id, u.name, u.username, u.class, u.avatar, u.created_at,
                   (SELECT COUNT(*) FROM memoirs m WHERE m.user_id = u.id) as memoir_count,
                   (SELECT COUNT(*) FROM followers f WHERE f.followed_id = u.id) as follower_count
            FROM users u
            WHERE u.name LIKE ? OR u.username LIKE ? OR u.class LIKE ?
            ORDER BY memoir_count DESC, u.created_at DESC
            LIMIT ? OFFSET ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssii", $searchTerm, $searchTerm, $searchTerm, $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    
    return [
        'items' => $users,
        'total' => $totalRows,
        'page' => $page,
        'limit' => $limit,
        'total_pages' => $totalPages
    ];
}

// 搜索相册
function searchAlbums($conn, $keyword, $page = 1, $limit = 20) {
    $keyword = trim($keyword);
    if (empty($keyword)) {
        return ['items' => [], 'total' => 0, 'page' => 1, 'limit' => $limit, 'total_pages' => 0];
    }
    
    $offset = ($page - 1) * $limit;
    $searchTerm = "%" . $keyword . "%";
    
    // 统计
    $countSql = "SELECT COUNT(*) as total FROM albums a WHERE a.name LIKE ? OR a.description LIKE ?";
    $countStmt = $conn->prepare($countSql);
    $countStmt->bind_param("ss", $searchTerm, $searchTerm);
    $countStmt->execute();
    $totalResult = $countStmt->get_result();
    $totalRows = $totalResult->fetch_assoc()['total'];
    $totalPages = ceil($totalRows / $limit);
    
    // 数据
    $sql = "SELECT a.*, u.name as owner_name, u.avatar as owner_avatar,
                   (SELECT COUNT(*) FROM photos p WHERE p.album_id = a.id) as photo_count
            FROM albums a
            JOIN users u ON a.user_id = u.id
            WHERE a.name LIKE ? OR a.description LIKE ?
            ORDER BY a.created_at DESC
            LIMIT ? OFFSET ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssii", $searchTerm, $searchTerm, $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $albums = [];
    while ($row = $result->fetch_assoc()) {
        $albums[] = $row;
    }
    
    return [
        'items' => $albums,
        'total' => $totalRows,
        'page' => $page,
        'limit' => $limit,
        'total_pages' => $totalPages
    ];
}

// 搜索照片
function searchPhotos($conn, $keyword, $page = 1, $limit = 30) {
    $keyword = trim($keyword);
    if (empty($keyword)) {
        return ['items' => [], 'total' => 0, 'page' => 1, 'limit' => $limit, 'total_pages' => 0];
    }
    
    $offset = ($page - 1) * $limit;
    $searchTerm = "%" . $keyword . "%";
    
    // 统计
    $countSql = "SELECT COUNT(*) as total FROM photos p WHERE p.title LIKE ? OR p.description LIKE ?";
    $countStmt = $conn->prepare($countSql);
    $countStmt->bind_param("ss", $searchTerm, $searchTerm);
    $countStmt->execute();
    $totalResult = $countStmt->get_result();
    $totalRows = $totalResult->fetch_assoc()['total'];
    $totalPages = ceil($totalRows / $limit);
    
    // 数据
    $sql = "SELECT p.*, u.name as uploader_name, u.avatar as uploader_avatar,
                   a.name as album_name, a.id as album_id,
                   (SELECT COUNT(*) FROM photo_likes pl WHERE pl.photo_id = p.id) as like_count
            FROM photos p
            JOIN users u ON p.user_id = u.id
            LEFT JOIN albums a ON p.album_id = a.id
            WHERE p.title LIKE ? OR p.description LIKE ?
            ORDER BY p.created_at DESC
            LIMIT ? OFFSET ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssii", $searchTerm, $searchTerm, $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $photos = [];
    while ($row = $result->fetch_assoc()) {
        $photos[] = $row;
    }
    
    return [
        'items' => $photos,
        'total' => $totalRows,
        'page' => $page,
        'limit' => $limit,
        'total_pages' => $totalPages
    ];
}

// 获取班级列表（用于筛选）
function getClassesForFilter($conn) {
    $sql = "SELECT c.id, c.name, c.graduation_id, g.year as graduation_year 
            FROM classes c 
            LEFT JOIN graduations g ON c.graduation_id = g.id 
            ORDER BY g.year DESC, c.name ASC";
    $result = $conn->query($sql);
    
    $classes = [];
    while ($row = $result->fetch_assoc()) {
        $classes[] = $row;
    }
    return $classes;
}

// 获取届别列表（用于筛选）
function getGraduationsForFilter($conn) {
    $sql = "SELECT id, year, name FROM graduations ORDER BY year DESC";
    $result = $conn->query($sql);
    
    $graduations = [];
    while ($row = $result->fetch_assoc()) {
        $graduations[] = $row;
    }
    return $graduations;
}

// 获取话题列表（用于筛选）
function getTopicsForFilter($conn) {
    $sql = "SELECT id, name FROM topics ORDER BY name ASC";
    $result = $conn->query($sql);
    
    $topics = [];
    while ($row = $result->fetch_assoc()) {
        $topics[] = $row;
    }
    return $topics;
}

// 路由处理
$userId = getCurrentUserId();

switch ($action) {
    case 'search_memoirs':
        $params = [
            'keyword' => $_GET['keyword'] ?? '',
            'author_id' => $_GET['author_id'] ?? 0,
            'class_id' => $_GET['class_id'] ?? 0,
            'graduation_id' => $_GET['graduation_id'] ?? 0,
            'topic_id' => $_GET['topic_id'] ?? 0,
            'date_from' => $_GET['date_from'] ?? '',
            'date_to' => $_GET['date_to'] ?? '',
            'has_media' => $_GET['has_media'] ?? -1,
            'page' => $_GET['page'] ?? 1,
            'limit' => $_GET['limit'] ?? 10
        ];
        
        $result = searchMemoirs($conn, $params, $userId);
        
        // 记录搜索历史
        if (!empty($params['keyword'])) {
            recordSearchHistory($conn, $userId, $params['keyword'], 'memoir', $result['total']);
        }
        
        echo json_encode([
            'success' => true,
            'type' => 'memoirs',
            'keyword' => $params['keyword'],
            'items' => $result['items'],
            'total' => $result['total'],
            'page' => $result['page'],
            'limit' => $result['limit'],
            'total_pages' => $result['total_pages']
        ]);
        break;
        
    case 'search_users':
        $keyword = $_GET['keyword'] ?? '';
        $page = intval($_GET['page'] ?? 1);
        $limit = min(50, intval($_GET['limit'] ?? 20));
        
        $result = searchUsers($conn, $keyword, $page, $limit);
        
        if (!empty($keyword)) {
            recordSearchHistory($conn, $userId, $keyword, 'user', $result['total']);
        }
        
        echo json_encode([
            'success' => true,
            'type' => 'users',
            'keyword' => $keyword,
            'items' => $result['items'],
            'total' => $result['total'],
            'page' => $result['page'],
            'limit' => $result['limit'],
            'total_pages' => $result['total_pages']
        ]);
        break;
        
    case 'search_albums':
        $keyword = $_GET['keyword'] ?? '';
        $page = intval($_GET['page'] ?? 1);
        $limit = min(50, intval($_GET['limit'] ?? 20));
        
        $result = searchAlbums($conn, $keyword, $page, $limit);
        
        if (!empty($keyword)) {
            recordSearchHistory($conn, $userId, $keyword, 'album', $result['total']);
        }
        
        echo json_encode([
            'success' => true,
            'type' => 'albums',
            'keyword' => $keyword,
            'items' => $result['items'],
            'total' => $result['total'],
            'page' => $result['page'],
            'limit' => $result['limit'],
            'total_pages' => $result['total_pages']
        ]);
        break;
        
    case 'search_photos':
        $keyword = $_GET['keyword'] ?? '';
        $page = intval($_GET['page'] ?? 1);
        $limit = min(50, intval($_GET['limit'] ?? 30));
        
        $result = searchPhotos($conn, $keyword, $page, $limit);
        
        if (!empty($keyword)) {
            recordSearchHistory($conn, $userId, $keyword, 'photo', $result['total']);
        }
        
        echo json_encode([
            'success' => true,
            'type' => 'photos',
            'keyword' => $keyword,
            'items' => $result['items'],
            'total' => $result['total'],
            'page' => $result['page'],
            'limit' => $result['limit'],
            'total_pages' => $result['total_pages']
        ]);
        break;
        
    case 'search_all':
        $keyword = $_GET['keyword'] ?? '';
        $page = intval($_GET['page'] ?? 1);
        
        $memoirs = searchMemoirs($conn, ['keyword' => $keyword, 'page' => 1, 'limit' => 5], $userId);
        $users = searchUsers($conn, $keyword, 1, 5);
        $albums = searchAlbums($conn, $keyword, 1, 4);
        $photos = searchPhotos($conn, $keyword, 1, 9);
        
        if (!empty($keyword)) {
            $totalResults = $memoirs['total'] + $users['total'] + $albums['total'] + $photos['total'];
            recordSearchHistory($conn, $userId, $keyword, 'all', $totalResults);
        }
        
        echo json_encode([
            'success' => true,
            'type' => 'all',
            'keyword' => $keyword,
            'memoirs' => $memoirs,
            'users' => $users,
            'albums' => $albums,
            'photos' => $photos
        ]);
        break;
        
    case 'hot_searches':
        $limit = min(50, intval($_GET['limit'] ?? 10));
        $hotSearches = getHotSearches($conn, $limit);
        echo json_encode(['success' => true, 'hot_searches' => $hotSearches]);
        break;
        
    case 'search_history':
        $limit = min(50, intval($_GET['limit'] ?? 20));
        $history = getUserSearchHistory($conn, $userId, $limit);
        echo json_encode(['success' => true, 'history' => $history]);
        break;
        
    case 'clear_history':
        $result = clearUserSearchHistory($conn, $userId);
        echo json_encode(['success' => $result, 'message' => $result ? '历史已清除' : '清除失败']);
        break;
        
    case 'get_filters':
        $classes = getClassesForFilter($conn);
        $graduations = getGraduationsForFilter($conn);
        $topics = getTopicsForFilter($conn);
        echo json_encode([
            'success' => true,
            'classes' => $classes,
            'graduations' => $graduations,
            'topics' => $topics
        ]);
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => '未知的操作']);
}
?>
