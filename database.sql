DROP DATABASE IF EXISTS high_school_memoir;
CREATE DATABASE IF NOT EXISTS high_school_memoir;
USE high_school_memoir;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    name VARCHAR(100) NOT NULL,
    class VARCHAR(50) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    avatar VARCHAR(255) DEFAULT NULL,
    background_image VARCHAR(255) DEFAULT NULL COMMENT '个人主页背景图',
    bio TEXT DEFAULT NULL COMMENT '个人简介/签名',
    visit_count INT DEFAULT 0 COMMENT '个人主页访问量',
    email VARCHAR(100) DEFAULT NULL COMMENT '邮箱',
    email_verified TINYINT(1) DEFAULT 0 COMMENT '邮箱是否已验证',
    last_login_at DATETIME DEFAULT NULL COMMENT '最后登录时间',
    last_login_ip VARCHAR(45) DEFAULT NULL COMMENT '最后登录IP',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS topics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_name (name),
    INDEX idx_created_at (created_at)
);

CREATE TABLE IF NOT EXISTS memoirs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    topic_id INT DEFAULT NULL,
    content TEXT NOT NULL,
    images TEXT, -- JSON array of image paths
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (topic_id) REFERENCES topics(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    memoir_id INT NOT NULL,
    user_id INT NOT NULL,
    content TEXT,
    image VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (memoir_id) REFERENCES memoirs(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS announcements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    content TEXT NOT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_created_at (created_at)
);

CREATE TABLE IF NOT EXISTS likes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    memoir_id INT NOT NULL,
    user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_like (memoir_id, user_id),
    FOREIGN KEY (memoir_id) REFERENCES memoirs(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- 私信消息表（必须先创建，因为conversations表会引用它）
CREATE TABLE IF NOT EXISTS messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT NOT NULL,
    sender_id INT NOT NULL,
    content TEXT NOT NULL,
    image VARCHAR(255) DEFAULT NULL COMMENT '消息图片',
    is_read TINYINT(1) DEFAULT 0,
    is_deleted TINYINT(1) DEFAULT 0 COMMENT '发送者删除标记',
    is_recalled TINYINT(1) DEFAULT 0 COMMENT '是否已撤回',
    recalled_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_conversation_id (conversation_id),
    INDEX idx_sender_id (sender_id),
    INDEX idx_is_read (is_read),
    INDEX idx_created_at (created_at)
);

-- 私信会话表
CREATE TABLE IF NOT EXISTS conversations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user1_id INT NOT NULL,
    user2_id INT NOT NULL,
    last_message_id INT DEFAULT NULL,
    last_message_time DATETIME DEFAULT NULL,
    user1_deleted TINYINT(1) DEFAULT 0 COMMENT '用户1删除标记',
    user2_deleted TINYINT(1) DEFAULT 0 COMMENT '用户2删除标记',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user1_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (user2_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (last_message_id) REFERENCES messages(id) ON DELETE SET NULL,
    UNIQUE KEY unique_conversation (user1_id, user2_id)
);

-- 添加conversation_id外键到messages表（在conversations表创建后）
ALTER TABLE messages ADD FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE;

-- 用户拉黑/屏蔽表
CREATE TABLE IF NOT EXISTS blocks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    blocker_id INT NOT NULL COMMENT '拉黑者',
    blocked_id INT NOT NULL COMMENT '被拉黑者',
    reason VARCHAR(100) DEFAULT NULL COMMENT '拉黑原因',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_block (blocker_id, blocked_id),
    FOREIGN KEY (blocker_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (blocked_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_blocker_id (blocker_id),
    INDEX idx_blocked_id (blocked_id)
);

-- 班级公告表
CREATE TABLE IF NOT EXISTS class_announcements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    class_id INT NOT NULL,
    author_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    content TEXT NOT NULL,
    is_pinned TINYINT(1) DEFAULT 0 COMMENT '是否置顶',
    view_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_class_id (class_id),
    INDEX idx_created_at (created_at)
);

-- 班级活动表
CREATE TABLE IF NOT EXISTS class_activities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    class_id INT NOT NULL,
    author_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    activity_type ENUM('gathering', 'vote', 'other') DEFAULT 'other',
    start_time DATETIME,
    end_time DATETIME,
    location VARCHAR(200),
    max_participants INT DEFAULT 0 COMMENT '最大参与人数，0表示不限',
    status ENUM('upcoming', 'ongoing', 'ended', 'cancelled') DEFAULT 'upcoming',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_class_id (class_id),
    INDEX idx_status (status),
    INDEX idx_start_time (start_time)
);

-- 班级活动参与表
CREATE TABLE IF NOT EXISTS class_activity_participants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    activity_id INT NOT NULL,
    user_id INT NOT NULL,
    status ENUM('going', 'interested', 'not_going') DEFAULT 'going',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_participation (activity_id, user_id),
    FOREIGN KEY (activity_id) REFERENCES class_activities(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id)
);

-- 班级投票表
CREATE TABLE IF NOT EXISTS class_polls (
    id INT AUTO_INCREMENT PRIMARY KEY,
    class_id INT NOT NULL,
    author_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    question_type ENUM('single', 'multiple') DEFAULT 'single',
    is_anonymous TINYINT(1) DEFAULT 0 COMMENT '是否匿名投票',
    allow_view_voters TINYINT(1) DEFAULT 1 COMMENT '是否允许查看投票人',
    end_time DATETIME,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_class_id (class_id),
    INDEX idx_is_active (is_active)
);

-- 班级投票选项表
CREATE TABLE IF NOT EXISTS class_poll_options (
    id INT AUTO_INCREMENT PRIMARY KEY,
    poll_id INT NOT NULL,
    option_text VARCHAR(200) NOT NULL,
    option_order INT DEFAULT 0,
    FOREIGN KEY (poll_id) REFERENCES class_polls(id) ON DELETE CASCADE,
    INDEX idx_poll_id (poll_id)
);

-- 班级投票记录表
CREATE TABLE IF NOT EXISTS class_poll_votes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    poll_id INT NOT NULL,
    option_id INT NOT NULL,
    user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_vote (poll_id, user_id),
    FOREIGN KEY (poll_id) REFERENCES class_polls(id) ON DELETE CASCADE,
    FOREIGN KEY (option_id) REFERENCES class_poll_options(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_poll_id (poll_id),
    INDEX idx_user_id (user_id)
);

-- 草稿箱表
CREATE TABLE IF NOT EXISTS drafts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    content TEXT,
    topic_id INT DEFAULT NULL,
    images JSON DEFAULT NULL COMMENT '图片JSON数组',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_updated_at (updated_at)
);

-- 相册表
CREATE TABLE IF NOT EXISTS albums (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(200) NOT NULL,
    description TEXT,
    cover_image VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_created_at (created_at)
);

-- 照片表
CREATE TABLE IF NOT EXISTS photos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    album_id INT NOT NULL,
    user_id INT NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    title VARCHAR(200),
    description TEXT,
    taken_at DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_album_id (album_id),
    INDEX idx_user_id (user_id),
    INDEX idx_taken_at (taken_at),
    INDEX idx_created_at (created_at)
);

-- 相册点赞表
CREATE TABLE IF NOT EXISTS album_likes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    album_id INT NOT NULL,
    user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_album_like (album_id, user_id),
    FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- 照片点赞表
CREATE TABLE IF NOT EXISTS photo_likes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    photo_id INT NOT NULL,
    user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_photo_like (photo_id, user_id),
    FOREIGN KEY (photo_id) REFERENCES photos(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- 回忆录媒体表（支持图片、视频、音频）
CREATE TABLE IF NOT EXISTS memoir_media (
    id INT AUTO_INCREMENT PRIMARY KEY,
    memoir_id INT NOT NULL,
    user_id INT NOT NULL,
    media_type ENUM('image', 'video', 'audio') NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_size BIGINT DEFAULT 0,
    duration INT DEFAULT 0,
    thumbnail_path VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (memoir_id) REFERENCES memoirs(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_memoir_id (memoir_id),
    INDEX idx_user_id (user_id),
    INDEX idx_media_type (media_type)
);

-- 为旧的images字段做向后兼容：新增has_media字段
ALTER TABLE memoirs ADD COLUMN IF NOT EXISTS media_count INT DEFAULT 0;
ALTER TABLE memoirs ADD COLUMN IF NOT EXISTS has_video TINYINT(1) DEFAULT 0;
ALTER TABLE memoirs ADD COLUMN IF NOT EXISTS has_audio TINYINT(1) DEFAULT 0;

-- 班级表
CREATE TABLE IF NOT EXISTS classes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    avatar VARCHAR(255),
    cover_image VARCHAR(255),
    invite_code VARCHAR(20) UNIQUE,
    created_by INT NOT NULL,
    is_private TINYINT(1) DEFAULT 0,
    member_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_invite_code (invite_code),
    INDEX idx_created_by (created_by)
);

-- 班级成员表
CREATE TABLE IF NOT EXISTS class_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    class_id INT NOT NULL,
    user_id INT NOT NULL,
    role ENUM('monitor', 'vice_monitor', 'member') DEFAULT 'member',
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_member (class_id, user_id),
    INDEX idx_class_id (class_id),
    INDEX idx_user_id (user_id)
);

-- 为memoirs表添加班级关联
ALTER TABLE memoirs ADD COLUMN IF NOT EXISTS class_id INT DEFAULT NULL;
ALTER TABLE memoirs ADD COLUMN is_class_post TINYINT(1) DEFAULT 0;
ALTER TABLE memoirs ADD INDEX idx_class_id (class_id);

-- 为albums表添加班级关联
ALTER TABLE albums ADD COLUMN IF NOT EXISTS class_id INT DEFAULT NULL;
ALTER TABLE albums ADD INDEX idx_class_id (class_id);

-- 届别表
CREATE TABLE IF NOT EXISTS graduations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    year INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    cover_image VARCHAR(255),
    created_by INT NOT NULL,
    class_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_year (year),
    INDEX idx_year (year)
);

-- 为班级表添加届别关联
ALTER TABLE classes ADD COLUMN IF NOT EXISTS graduation_id INT DEFAULT NULL;
ALTER TABLE classes ADD INDEX idx_graduation_id (graduation_id);

-- 用户表添加管理员字段
ALTER TABLE users ADD COLUMN IF NOT EXISTS is_admin TINYINT(1) DEFAULT 0;
ALTER TABLE users ADD INDEX idx_is_admin (is_admin);

-- 系统配置表
CREATE TABLE IF NOT EXISTS site_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    config_key VARCHAR(100) NOT NULL UNIQUE,
    config_value TEXT,
    config_type ENUM('string', 'number', 'boolean', 'json') DEFAULT 'string',
    description VARCHAR(255),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_config_key (config_key)
);

-- 登录尝试表（用于防暴力破解）
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    username VARCHAR(50),
    success TINYINT(1) DEFAULT 0,
    attempt_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_time (ip_address, attempt_time),
    INDEX idx_username_time (username, attempt_time)
);

-- 搜索历史表
CREATE TABLE IF NOT EXISTS search_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    keyword VARCHAR(200) NOT NULL,
    search_type ENUM('memoir', 'user', 'album', 'photo', 'all') DEFAULT 'all',
    result_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_keyword (keyword),
    INDEX idx_created_at (created_at)
);

-- 热门搜索词表
CREATE TABLE IF NOT EXISTS hot_searches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    keyword VARCHAR(200) NOT NULL UNIQUE,
    search_count INT DEFAULT 0,
    last_search_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_search_count (search_count),
    INDEX idx_last_search (last_search_at)
);

-- 用户关系表（关注系统）
CREATE TABLE IF NOT EXISTS follows (
    id INT AUTO_INCREMENT PRIMARY KEY,
    follower_id INT NOT NULL COMMENT '关注者',
    following_id INT NOT NULL COMMENT '被关注者',
    is_special TINYINT(1) DEFAULT 0 COMMENT '是否特别关注',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_follow (follower_id, following_id),
    FOREIGN KEY (follower_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (following_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_follower_id (follower_id),
    INDEX idx_following_id (following_id),
    INDEX idx_is_special (is_special)
);

-- 收藏表
CREATE TABLE IF NOT EXISTS favorites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    memoir_id INT NOT NULL,
    user_id INT NOT NULL,
    folder_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_favorite (memoir_id, user_id),
    FOREIGN KEY (memoir_id) REFERENCES memoirs(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_memoir_id (memoir_id)
);

-- 转发/分享表
CREATE TABLE IF NOT EXISTS shares (
    id INT AUTO_INCREMENT PRIMARY KEY,
    memoir_id INT NOT NULL,
    user_id INT NOT NULL,
    share_text TEXT,
    share_type ENUM('internal', 'wechat', 'qq', 'weibo', 'link', 'other') DEFAULT 'internal',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (memoir_id) REFERENCES memoirs(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_memoir_id (memoir_id),
    INDEX idx_created_at (created_at)
);

-- 为memoirs添加分享计数字段
ALTER TABLE memoirs ADD COLUMN IF NOT EXISTS share_count INT DEFAULT 0;
ALTER TABLE memoirs ADD COLUMN IF NOT EXISTS favorite_count INT DEFAULT 0;

-- 标签表（回忆录分类标签）
CREATE TABLE IF NOT EXISTS tags (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    use_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_name (name),
    INDEX idx_use_count (use_count)
);

-- 回忆录-标签关联表
CREATE TABLE IF NOT EXISTS memoir_tags (
    id INT AUTO_INCREMENT PRIMARY KEY,
    memoir_id INT NOT NULL,
    tag_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_memoir_tag (memoir_id, tag_id),
    FOREIGN KEY (memoir_id) REFERENCES memoirs(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE,
    INDEX idx_memoir_id (memoir_id),
    INDEX idx_tag_id (tag_id)
);

-- 评论表添加父评论ID（支持二级评论/回复）
ALTER TABLE comments ADD COLUMN IF NOT EXISTS parent_id INT DEFAULT NULL;
ALTER TABLE comments ADD COLUMN IF NOT EXISTS reply_to_user_id INT DEFAULT NULL;
ALTER TABLE comments ADD INDEX idx_parent_id (parent_id);
ALTER TABLE comments ADD COLUMN IF NOT EXISTS like_count INT DEFAULT 0;

-- 评论点赞表
CREATE TABLE IF NOT EXISTS comment_likes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    comment_id INT NOT NULL,
    user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_comment_like (comment_id, user_id),
    FOREIGN KEY (comment_id) REFERENCES comments(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_comment_id (comment_id),
    INDEX idx_user_id (user_id)
);

-- @提及表
CREATE TABLE IF NOT EXISTS mentions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    memoir_id INT DEFAULT NULL,
    comment_id INT DEFAULT NULL,
    mentioned_user_id INT NOT NULL,
    mentioner_user_id INT NOT NULL,
    mention_type ENUM('memoir', 'comment') NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (memoir_id) REFERENCES memoirs(id) ON DELETE CASCADE,
    FOREIGN KEY (comment_id) REFERENCES comments(id) ON DELETE CASCADE,
    FOREIGN KEY (mentioned_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (mentioner_user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_mentioned_user (mentioned_user_id),
    INDEX idx_mentioner_user (mentioner_user_id),
    INDEX idx_is_read (is_read)
);
