const API_BASE = 'api/';
let csrfToken = '';

function setCSRFToken(token) {
    if (token) {
        csrfToken = token;
        localStorage.setItem('csrf_token', token);
    }
}

function getCSRFToken() {
    return csrfToken || localStorage.getItem('csrf_token') || '';
}

async function apiFetch(endpoint, options = {}) {
    const url = endpoint.startsWith('http') ? endpoint : API_BASE + endpoint;
    const token = getCSRFToken();
    
    if (token) {
        if (!options.headers) {
            options.headers = {};
        }
        options.headers['X-CSRF-Token'] = token;
    }
    
    const response = await fetch(url, options);
    
    const newToken = response.headers.get('X-CSRF-Token');
    if (newToken) {
        setCSRFToken(newToken);
    }
    
    return response;
}

document.addEventListener('DOMContentLoaded', async () => {
    const isInstallPage = window.location.pathname.endsWith('install.html');
    const isAdminPage = window.location.pathname.endsWith('admin.html');
    
    if (!isInstallPage && !isAdminPage) {
        const installed = await checkInstallStatus();
        if (!installed) {
            window.location.href = 'install.html';
            return;
        }
    }
    
    if (isInstallPage) {
        const installed = await checkInstallStatus();
        if (installed) {
            window.location.href = 'index.html';
            return;
        }
    }

    checkSession().then(() => {
        // After session check
        const feed = document.getElementById('memoir-feed');
        if (feed) {
            const mode = feed.getAttribute('data-mode');
            if (mode === 'my-posts') {
                if (currentUser) {
                    loadMemoirs('', currentUser.id);
                    loadNotifications();
                    renderProfileInfo();
                } else {
                    window.location.href = 'login.html';
                }
            } else if (mode === 'search') {
                // Search page: only load popular list initially, wait for user search
                loadPopular();
            } else {
                loadMemoirs();
                loadAnnouncements();
                loadTopics();
                loadTopicRanking();
            }
        }
    });

    // Search
    const searchInput = document.getElementById('search-input');
    if (searchInput) {
        searchInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                loadMemoirs(searchInput.value);
            }
        });
    }
    
    // Post Form
    const postForm = document.getElementById('post-form');
    if (postForm) {
        postForm.addEventListener('submit', handlePost);
    }
    
    // Auth Forms
    const loginForm = document.getElementById('login-form');
    if (loginForm) handleAuth(loginForm, 'login');
    
    const registerForm = document.getElementById('register-form');
    if (registerForm) handleAuth(registerForm, 'register');
    
    const recoverForm = document.getElementById('recover-form');
    if (recoverForm) handleAuth(recoverForm, 'recover');
    
    // Profile Edit Form
    const editProfileForm = document.getElementById('edit-profile-form');
    if (editProfileForm) {
        editProfileForm.addEventListener('submit', handleUpdateProfile);
    }
});

// 全局状态
let currentUser = null;
let unreadMessageCount = 0;
let messagePollingInterval = null;

async function checkInstallStatus() {
    try {
        const formData = new FormData();
        formData.append('action', 'get_public_config');
        const res = await fetch(API_BASE + 'auth.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success && data.configs) {
            return data.configs.site_installed === true || data.configs.site_installed === '1';
        }
        return false;
    } catch (e) {
        return false;
    }
}

function toggleEditProfile() {
    const card = document.getElementById('edit-profile-card');
    if (card.style.display === 'none') {
        card.style.display = 'block';
        if (currentUser) {
            document.getElementById('edit-name-input').value = currentUser.name;
        }
    } else {
        card.style.display = 'none';
    }
}

async function handleUpdateProfile(e) {
    e.preventDefault();
    const formData = new FormData(e.target);
    formData.append('action', 'update_profile');
    
    try {
        const res = await fetch(API_BASE + 'auth.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            alert('更新成功');
            currentUser = data.user;
            renderProfileInfo();
            toggleEditProfile();
            // Reload page to update avatars in feed if necessary, or we can just reload
            window.location.reload(); 
        } else {
            alert(data.message);
        }
    } catch (e) {
        alert('更新失败');
    }
}

function getAvatarHtml(userOrName, size = '40px', fontSize = '1rem') {
    let name = '';
    let avatar = null;
    
    if (typeof userOrName === 'object') {
        name = userOrName.name || userOrName.author_name || '?';
        avatar = userOrName.avatar || null;
    } else {
        name = userOrName;
    }
    
    if (avatar) {
        return `<img src="${escapeHtml(avatar)}" class="user-avatar-img" style="width: ${size}; height: ${size};">`;
    } else {
        return `<div class="avatar-placeholder" style="width: ${size}; height: ${size}; font-size: ${fontSize};">${escapeHtml(name[0] || '?')}</div>`;
    }
}

async function checkSession() {
    try {
        const formData = new FormData();
        formData.append('action', 'check_session');
        const res = await fetch(API_BASE + 'auth.php', { method: 'POST', body: formData });
        const data = await res.json();
        
        if (data.csrf_token) {
            setCSRFToken(data.csrf_token);
        }
        
        const navAuth = document.getElementById('nav-auth');
        const profileSidebar = document.getElementById('profile-sidebar');
        
        if (data.success) {
            currentUser = data.user;
            if (navAuth) {
                let adminLink = '';
                if (currentUser.is_admin == 1 || currentUser.is_admin === true) {
                    adminLink = '<a href="admin.html" class="nav-item">⚙️ 管理后台</a>';
                }
                navAuth.innerHTML = `
                    <a href="index.html" class="nav-item">首页</a>
                    <a href="graduations.html" class="nav-item">🎓 届别</a>
                    <a href="classes.html" class="nav-item">🏫 班级</a>
                    <a href="messages.html" class="nav-item" id="nav-messages">💬 私信</a>
                    <a href="albums.html" class="nav-item">📷 相册</a>
                    ${adminLink}
                    <a href="profile.html" class="nav-item">个人中心</a>
                    <span class="nav-item" onclick="logout()">退出</span>
                `;
            }
            if (profileSidebar) {
                profileSidebar.innerHTML = `
                    <div class="card">
                        <div class="sidebar-title">个人信息</div>
                        <div class="profile-info">
                            <div style="margin: 0 auto 10px; display: flex; justify-content: center;">
                                ${getAvatarHtml(data.user, '60px', '1.5rem')}
                            </div>
                            <h3 style="text-align: center;">${data.user.name}</h3>
                            <p style="text-align: center; color: #666;">${data.user.class}</p>
                            <div style="margin-top: 15px; text-align: center;">
                                <a href="messages.html" class="btn" style="background: #6c5ce7; color: white; margin-right: 5px;">💬 私信</a>
                                <a href="albums.html" class="btn" style="background: #00b894; color: white; margin-right: 5px;">📷 相册</a>
                                <a href="classes.html" class="btn" style="background: #fdcb6e; color: #333; margin-right: 5px;">🏫 班级</a>
                                <a href="profile.html" class="btn btn-primary" style="font-size: 0.8rem;">管理账号</a>
                            </div>
                        </div>
                    </div>
                `;
            }
            // 启动私信未读数轮询
            startMessagePolling();
        } else {
            currentUser = null;
            if (navAuth) {
                navAuth.innerHTML = `
                    <a href="index.html" class="nav-item">首页</a>
                    <a href="login.html" class="nav-item">登录</a>
                    <a href="register.html" class="btn btn-primary">注册</a>
                `;
            }
            if (profileSidebar) {
                profileSidebar.innerHTML = `
                    <div class="card">
                        <div class="sidebar-title">未登录</div>
                        <p>登录后查看个人信息和发布回忆。</p>
                        <div style="margin-top: 15px; text-align: center;">
                            <a href="login.html" class="btn btn-primary">立即登录</a>
                        </div>
                    </div>
                `;
            }
            // Hide post box
            const postBox = document.getElementById('create-post-container');
            if (postBox) postBox.style.display = 'none';
        }
    } catch (e) {
        console.error("Session check failed", e);
    }
}

async function logout() {
    const formData = new FormData();
    formData.append('action', 'logout');
    await fetch(API_BASE + 'auth.php', { method: 'POST', body: formData });
    window.location.reload();
}

function handleAuth(form, action) {
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const formData = new FormData(form);
        formData.append('action', action);
        
        try {
            const res = await fetch(API_BASE + 'auth.php', { method: 'POST', body: formData });
            const data = await res.json();
            alert(data.message);
            if (data.success) {
                if (action === 'login' || action === 'register') {
                    window.location.href = 'index.html';
                } else if (action === 'recover') {
                    window.location.href = 'login.html';
                }
            }
        } catch (e) {
            alert('操作失败');
        }
    });
}

async function loadMemoirs(search = '', userId = 0, topicId = 0, page = 1) {
    const feed = document.getElementById('memoir-feed');
    if (!feed) return;
    
    // Update global filters if arguments are provided (reset page if filters change)
    if (page === 1) {
        currentFilters.search = search;
        currentFilters.userId = userId;
        currentFilters.topicId = topicId;
        currentPage = 1;
    } else {
        currentPage = page;
        // Use stored filters
        search = currentFilters.search;
        userId = currentFilters.userId;
        topicId = currentFilters.topicId;
    }

    feed.innerHTML = '<div style="text-align:center; padding: 20px;">加载中...</div>';
    
    let url = `${API_BASE}memoirs.php?action=list&search=${encodeURIComponent(search)}&page=${currentPage}&limit=5`;
    if (userId > 0) {
        url += `&user_id=${userId}`;
    }
    if (topicId > 0) {
        url += `&topic_id=${topicId}`;
    }

    try {
        const res = await fetch(url);
        const data = await res.json();
        
        if (data.success) {
            feed.innerHTML = '';
            if (data.memoirs.length === 0) {
                feed.innerHTML = '<div style="text-align:center; padding: 20px; color: #888;">暂无回忆</div>';
                // Also clear pagination
                const paginationContainer = document.getElementById('pagination');
                if (paginationContainer) paginationContainer.innerHTML = '';
                return;
            }
            
            data.memoirs.forEach(memoir => {
                const card = document.createElement('div');
                card.className = 'card memoir-post';
                
                let imagesHtml = '';
                if (memoir.images && memoir.images.length > 0) {
                    imagesHtml = '<div class="post-images">';
                    memoir.images.forEach(img => {
                        imagesHtml += `<img src="${img}" onclick="window.open('${img}', '_blank')">`;
                    });
                    imagesHtml += '</div>';
                }
                
                let mediaHtml = '';
                if (memoir.media && memoir.media.length > 0) {
                    memoir.media.forEach(media => {
                        if (media.media_type === 'video') {
                            mediaHtml += `
                                <div style="margin: 10px 0; border-radius: 8px; overflow: hidden; background: #000;">
                                    <video controls style="width: 100%; max-height: 400px;" preload="metadata">
                                        <source src="${media.file_path}">
                                        您的浏览器不支持视频播放
                                    </video>
                                </div>
                            `;
                        } else if (media.media_type === 'audio') {
                            mediaHtml += `
                                <div style="margin: 10px 0; padding: 15px; background: linear-gradient(135deg, #fff3e0, #ffe0b2); border-radius: 12px;">
                                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 10px;">
                                        <div style="font-size: 1.5rem;">🎵</div>
                                        <div style="flex: 1;">
                                            <div style="font-weight: 500; font-size: 0.9rem;">音频回忆</div>
                                            <div style="font-size: 0.75rem; color: #e65100;">${(media.file_size / 1024 / 1024).toFixed(1)} MB</div>
                                        </div>
                                    </div>
                                    <audio controls style="width: 100%;">
                                        <source src="${media.file_path}">
                                        您的浏览器不支持音频播放
                                    </audio>
                                </div>
                            `;
                        } else if (media.media_type === 'image') {
                            imagesHtml = imagesHtml || '<div class="post-images">';
                            imagesHtml += `<img src="${media.file_path}" onclick="window.open('${media.file_path}', '_blank')">`;
                        }
                    });
                    if (imagesHtml && !imagesHtml.endsWith('</div>')) {
                        imagesHtml += '</div>';
                    }
                }
                
                const deleteBtn = (currentUser && currentUser.id == memoir.user_id) 
                    ? `<span style="margin-left: auto; color: red; cursor: pointer; font-size: 0.8rem;" onclick="deleteMemoir(${memoir.id})">删除</span>` 
                    : '';
                
                const likeClass = memoir.is_liked > 0 ? 'active' : '';
                
                const topicHtml = memoir.topic_name ? `<span style="color: var(--primary-color); font-size: 0.9rem; margin-right: 10px; cursor: pointer;" onclick="loadMemoirs('', 0, ${memoir.topic_id})">#${escapeHtml(memoir.topic_name)}</span>` : '';
                
                card.innerHTML = `
                    <div class="post-header">
                        <div style="margin-right: 10px;">${getAvatarHtml({name: memoir.author_name, avatar: memoir.author_avatar}, '40px')}</div>
                        <div class="post-info">
                            <h4>${escapeHtml(memoir.author_name)} <span style="font-weight:normal; font-size: 0.8rem;">(${escapeHtml(memoir.author_class)})</span></h4>
                            <span>${new Date(memoir.created_at).toLocaleString()}</span>
                        </div>
                        ${deleteBtn}
                    </div>
                    <div class="post-content">${topicHtml}${escapeHtml(memoir.content)}</div>
                    ${imagesHtml}
                    ${mediaHtml}
                    <div class="post-actions">
                        <div class="action-btn ${likeClass}" onclick="toggleLike(this, ${memoir.id})">
                            <span>❤️</span> <span class="count">${memoir.likes_count}</span>
                        </div>
                        <div class="action-btn" onclick="toggleComments(${memoir.id})">
                            <span>💬</span> <span class="count">${memoir.comments_count}</span>
                        </div>
                        ${currentUser && currentUser.id == memoir.user_id ? `
                        <div class="action-btn" onclick="event.stopPropagation(); openExportModal('memoir', ${memoir.id})">
                            <span>📥</span> <span>导出</span>
                        </div>` : ''}
                    </div>
                    <div class="comments-section" id="comments-${memoir.id}">
                        <div class="comments-list" id="comments-list-${memoir.id}"></div>
                        <div class="comment-form" style="margin-top: 10px; display: flex; gap: 10px;">
                            <input type="text" id="comment-input-${memoir.id}" placeholder="写下你的评论..." style="flex: 1; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                            <button class="btn btn-primary" style="padding: 5px 15px; font-size: 0.8rem;" onclick="addComment(${memoir.id})">发送</button>
                        </div>
                    </div>
                `;
                feed.appendChild(card);
            });
            
            renderPagination(data.pagination);
        }
    } catch (e) {
        console.error("Load memoirs failed", e);
        feed.innerHTML = '<div style="text-align:center; padding: 20px; color: red;">加载失败</div>';
    }
}

function renderPagination(pagination) {
    const container = document.getElementById('pagination');
    if (!container) return;
    
    container.innerHTML = '';
    
    if (pagination.total_pages <= 1) return;
    
    const { page, total_pages } = pagination;
    
    // Helper to call loadMemoirs with current filters
    const loadPage = (p) => {
        loadMemoirs(currentFilters.search, currentFilters.userId, currentFilters.topicId, p);
    };
    
    // Prev button
    const prevBtn = document.createElement('button');
    prevBtn.className = 'page-btn';
    prevBtn.innerText = '上一页';
    prevBtn.disabled = page === 1;
    prevBtn.onclick = () => loadPage(page - 1);
    container.appendChild(prevBtn);
    
    for (let i = 1; i <= total_pages; i++) {
        if (i === 1 || i === total_pages || (i >= page - 2 && i <= page + 2)) {
            const btn = document.createElement('button');
            btn.className = `page-btn ${i === page ? 'active' : ''}`;
            btn.innerText = i;
            btn.onclick = () => loadPage(i);
            container.appendChild(btn);
        } else if (i === page - 3 || i === page + 3) {
             const span = document.createElement('span');
             span.innerText = '...';
             span.style.margin = '0 5px';
             container.appendChild(span);
        }
    }
    
    // Next button
    const nextBtn = document.createElement('button');
    nextBtn.className = 'page-btn';
    nextBtn.innerText = '下一页';
    nextBtn.disabled = page === total_pages;
    nextBtn.onclick = () => loadPage(page + 1);
    container.appendChild(nextBtn);
}

let selectedFiles = [];
let selectedVideos = [];
let selectedAudios = [];
let currentPage = 1;
let currentFilters = {
    search: '',
    userId: 0,
    topicId: 0
};

function openPostModal() {
    if (!currentUser) {
        window.location.href = 'login.html';
        return;
    }
    const modal = document.getElementById('post-modal');
    if (modal) {
        modal.style.display = 'flex';
        // Reset selected files when opening modal (optional, or keep them)
        // selectedFiles = [];
        // renderImagePreviews();
    }
}

function closePostModal() {
    const modal = document.getElementById('post-modal');
    if (modal) {
        modal.style.display = 'none';
    }
}

function handleImageSelection(input) {
    if (input.files && input.files.length > 0) {
        Array.from(input.files).forEach(file => {
            selectedFiles.push(file);
        });
        renderImagePreviews();
        input.value = '';
    }
}

function handleVideoSelection(input) {
    if (input.files && input.files.length > 0) {
        Array.from(input.files).forEach(file => {
            if (file.size <= 100 * 1024 * 1024) {
                selectedVideos.push(file);
            } else {
                alert('视频 ' + file.name + ' 超过100MB限制');
            }
        });
        renderVideoPreviews();
        input.value = '';
    }
}

function handleAudioSelection(input) {
    if (input.files && input.files.length > 0) {
        Array.from(input.files).forEach(file => {
            if (file.size <= 50 * 1024 * 1024) {
                selectedAudios.push(file);
            } else {
                alert('音频 ' + file.name + ' 超过50MB限制');
            }
        });
        renderAudioPreviews();
        input.value = '';
    }
}

function removeImage(index) {
    selectedFiles.splice(index, 1);
    renderImagePreviews();
}

function removeVideo(index) {
    selectedVideos.splice(index, 1);
    renderVideoPreviews();
}

function removeAudio(index) {
    selectedAudios.splice(index, 1);
    renderAudioPreviews();
}

function renderImagePreviews() {
    const container = document.getElementById('image-preview-container');
    if (!container) return;
    
    // Clear current previews but keep the upload trigger
    // We will rebuild the innerHTML.
    // Ideally we should append before the trigger, but simplest is to clear and rebuild.
    
    container.innerHTML = '';
    
    selectedFiles.forEach((file, index) => {
        const reader = new FileReader();
        reader.onload = function(e) {
            const div = document.createElement('div');
            div.className = 'preview-item';
            div.innerHTML = `
                <img src="${e.target.result}" class="preview-thumbnail">
                <div class="preview-remove" onclick="removeImage(${index})">&times;</div>
            `;
            // Insert before the last element (which will be the trigger)
            // But since FileReader is async, we need a stable way. 
            // Actually, rebuilding all is tricky with async FileReader.
            // Better approach: create elements immediately with placeholder or wait?
            // Since local files read fast, we can just append to a specific part.
            // Let's change strategy: container has a 'list' part and a 'trigger' part?
            // Or just append 'div' and set img src later.
        }
        // Sync approach for structure, async for content
        const div = document.createElement('div');
        div.className = 'preview-item';
        // We need a closure or let to capture index correctly if we regenerate all
        // But since we clear container, we must regenerate all.
        // Wait, reader.onload is async. If we loop, the index might be correct in closure.
        
        // Let's use a simpler way: Generate all divs first, then trigger readers.
        
        div.innerHTML = `<img src="" class="preview-thumbnail" id="preview-img-${index}">
                         <div class="preview-remove" onclick="removeImage(${index})">&times;</div>`;
        container.appendChild(div);
        
        reader.onload = (e) => {
            const img = document.getElementById(`preview-img-${index}`);
            if (img) img.src = e.target.result;
        };
        reader.readAsDataURL(file);
    });
    
    // Append trigger button
    const trigger = document.createElement('div');
    trigger.className = 'upload-trigger';
    trigger.innerHTML = '+';
    trigger.onclick = () => document.getElementById('post-images').click();
    container.appendChild(trigger);
}

function renderVideoPreviews() {
    const container = document.getElementById('video-preview-container');
    if (!container) return;
    
    container.innerHTML = '';
    
    selectedVideos.forEach((file, index) => {
        const div = document.createElement('div');
        div.className = 'preview-item';
        div.style.width = '120px';
        div.style.height = '90px';
        div.style.position = 'relative';
        div.style.borderRadius = '8px';
        div.style.overflow = 'hidden';
        div.style.background = '#000';
        
        const video = document.createElement('video');
        video.src = URL.createObjectURL(file);
        video.style.width = '100%';
        video.style.height = '100%';
        video.style.objectFit = 'cover';
        video.muted = true;
        
        const removeBtn = document.createElement('div');
        removeBtn.className = 'preview-remove';
        removeBtn.style.cssText = 'position:absolute;top:5px;right:5px;width:22px;height:22px;border-radius:50%;background:rgba(0,0,0,0.7);color:white;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:1rem;';
        removeBtn.innerHTML = '&times;';
        removeBtn.onclick = (e) => { e.stopPropagation(); removeVideo(index); };
        
        const videoIcon = document.createElement('div');
        videoIcon.style.cssText = 'position:absolute;bottom:5px;left:5px;color:white;font-size:0.75rem;background:rgba(0,0,0,0.6);padding:2px 6px;border-radius:4px;';
        videoIcon.textContent = '🎬 ' + (file.size / 1024 / 1024).toFixed(1) + 'MB';
        
        div.appendChild(video);
        div.appendChild(removeBtn);
        div.appendChild(videoIcon);
        container.appendChild(div);
    });
    
    const trigger = document.createElement('div');
    trigger.className = 'upload-trigger';
    trigger.style.cssText = 'background: #e8f5e9; color: #2e7d32;';
    trigger.innerHTML = '+ 视频';
    trigger.onclick = () => document.getElementById('post-videos').click();
    container.appendChild(trigger);
}

function renderAudioPreviews() {
    const container = document.getElementById('audio-preview-container');
    if (!container) return;
    
    container.innerHTML = '';
    
    selectedAudios.forEach((file, index) => {
        const div = document.createElement('div');
        div.className = 'preview-item';
        div.style.width = '200px';
        div.style.minHeight = '60px';
        div.style.position = 'relative';
        div.style.borderRadius = '8px';
        div.style.background = 'linear-gradient(135deg, #fff3e0, #ffe0b2)';
        div.style.padding = '10px';
        div.style.display = 'flex';
        div.style.alignItems = 'center';
        div.style.gap = '10px';
        
        const icon = document.createElement('div');
        icon.style.cssText = 'font-size: 1.5rem;';
        icon.textContent = '🎵';
        
        const info = document.createElement('div');
        info.style.cssText = 'flex:1; min-width:0;';
        info.innerHTML = `
            <div style="font-size: 0.8rem; font-weight: 500; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${escapeHtml(file.name)}</div>
            <div style="font-size: 0.7rem; color: #e65100;">${(file.size / 1024 / 1024).toFixed(1)} MB</div>
        `;
        
        const removeBtn = document.createElement('div');
        removeBtn.style.cssText = 'width:22px;height:22px;border-radius:50%;background:rgba(230,81,0,0.7);color:white;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:1rem;flex-shrink:0;';
        removeBtn.innerHTML = '&times;';
        removeBtn.onclick = (e) => { e.stopPropagation(); removeAudio(index); };
        
        div.appendChild(icon);
        div.appendChild(info);
        div.appendChild(removeBtn);
        container.appendChild(div);
    });
    
    const trigger = document.createElement('div');
    trigger.className = 'upload-trigger';
    trigger.style.cssText = 'background: #fff3e0; color: #e65100;';
    trigger.innerHTML = '+ 音频';
    trigger.onclick = () => document.getElementById('post-audios').click();
    container.appendChild(trigger);
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('post-modal');
    if (event.target == modal) {
        modal.style.display = 'none';
    }
}

async function handlePost(e) {
    e.preventDefault();
    const formData = new FormData(e.target);
    formData.append('action', 'create');
    
    formData.delete('images[]');
    formData.delete('videos[]');
    formData.delete('audios[]');
    
    selectedFiles.forEach(file => {
        formData.append('images[]', file);
    });
    
    selectedVideos.forEach(file => {
        formData.append('videos[]', file);
    });
    
    selectedAudios.forEach(file => {
        formData.append('audios[]', file);
    });
    
    try {
        const res = await fetch(API_BASE + 'memoirs.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            e.target.reset();
            selectedFiles = [];
            selectedVideos = [];
            selectedAudios = [];
            renderImagePreviews();
            renderVideoPreviews();
            renderAudioPreviews();
            
            closePostModal();
            loadMemoirs();
            loadTopicRanking();
        } else {
            alert(data.message);
        }
    } catch (e) {
        console.error("Post failed", e);
        alert('发布失败');
    }
}

async function deleteMemoir(id) {
    if (!confirm('确定要删除这条回忆吗？')) return;
    
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('memoir_id', id);
    
    const res = await fetch(API_BASE + 'memoirs.php', { method: 'POST', body: formData });
    const data = await res.json();
    if (data.success) {
        loadMemoirs();
    } else {
        alert(data.message);
    }
}

async function toggleLike(btn, memoirId) {
    if (!currentUser) {
        alert('请先登录');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'toggle_like');
    formData.append('memoir_id', memoirId);
    
    const res = await fetch(API_BASE + 'interactions.php', { method: 'POST', body: formData });
    const data = await res.json();
    
    if (data.success) {
        const countSpan = btn.querySelector('.count');
        let count = parseInt(countSpan.innerText);
        if (data.liked) {
            btn.classList.add('active');
            count++;
        } else {
            btn.classList.remove('active');
            count--;
        }
        countSpan.innerText = count;
    }
}

async function toggleComments(memoirId) {
    const section = document.getElementById(`comments-${memoirId}`);
    if (section.style.display === 'block') {
        section.style.display = 'none';
    } else {
        section.style.display = 'block';
        loadComments(memoirId);
    }
}

async function loadComments(memoirId) {
    const list = document.getElementById(`comments-list-${memoirId}`);
    list.innerHTML = '加载中...';
    
    const res = await fetch(`${API_BASE}interactions.php?action=get_comments&memoir_id=${memoirId}`);
    const data = await res.json();
    
    if (data.success) {
        list.innerHTML = '';
        data.comments.forEach(c => {
            const div = document.createElement('div');
            div.className = 'comment';
            let imgHtml = c.image ? `<br><img src="${escapeHtml(c.image)}" style="max-width: 100px; max-height: 100px; margin-top: 5px; border-radius: 4px;">` : '';
            div.innerHTML = `
                <div style="margin-right: 10px;">${getAvatarHtml({name: c.author_name, avatar: c.author_avatar}, '30px', '0.8rem')}</div>
                <div class="comment-content">
                    <div class="comment-author">${escapeHtml(c.author_name)}</div>
                    <div class="comment-text">${escapeHtml(c.content)}${imgHtml}</div>
                </div>
            `;
            list.appendChild(div);
        });
    }
}

async function addComment(memoirId) {
    const input = document.getElementById(`comment-input-${memoirId}`);
    
    if (!input) {
        console.error("Comment input not found for memoir", memoirId);
        return;
    }

    const content = input.value;
    
    if (!content) {
        alert('请输入评论内容');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'add_comment');
    formData.append('memoir_id', memoirId);
    formData.append('content', content);
    
    try {
        const res = await fetch(API_BASE + 'interactions.php', { method: 'POST', body: formData });
        const data = await res.json();
        
        if (data.success) {
            input.value = '';
            loadComments(memoirId);
            // Increment comment count in UI immediately
            // Find the comment count span
            // This requires traversing the DOM or reloading the memoir, but reloading is heavy.
            // Let's just reload comments for now, user can see their comment.
        } else {
            alert(data.message);
        }
    } catch (e) {
        console.error("Post comment failed", e);
        alert('发送失败');
    }
}

async function loadPopular() {
    const list = document.getElementById('popular-list');
    if (!list) return;
    
    try {
        const res = await fetch(`${API_BASE}memoirs.php?action=popular`);
        const data = await res.json();
        
        if (data.success) {
            list.innerHTML = '';
            if (data.memoirs.length === 0) {
                 list.innerHTML = '<div style="padding:10px; color:#888;">暂无数据</div>';
                 return;
            }
            data.memoirs.forEach(m => {
                const div = document.createElement('div');
                div.className = 'popular-item';
                div.innerHTML = `
                    <div class="popular-title"><a href="#" onclick="alert('请在主列表中搜索查看完整内容')">${escapeHtml(m.content)}</a></div>
                    <div class="popular-meta">by ${escapeHtml(m.author_name)} · ${m.likes_count} likes</div>
                `;
                list.appendChild(div);
            });
        } else {
             list.innerHTML = `<div style="padding:10px; color:red;">加载失败: ${data.message}</div>`;
        }
    } catch (e) {
        console.error("Load popular failed", e);
        list.innerHTML = '<div style="padding:10px; color:red;">网络或服务器错误</div>';
    }
}

async function loadTopics() {
    const datalist = document.getElementById('topic-suggestions');
    if (!datalist) return;
    
    try {
        const res = await fetch(`${API_BASE}topics.php?action=list`);
        const data = await res.json();
        
        if (data.success) {
            datalist.innerHTML = '';
            data.topics.forEach(t => {
                datalist.innerHTML += `<option value="${escapeHtml(t.name)}">`;
            });
        }
    } catch (e) {
        console.error("Load topics failed", e);
    }
}

async function loadTopicRanking() {
    const list = document.getElementById('topic-ranking-list');
    if (!list) return;
    
    try {
        const res = await fetch(`${API_BASE}topics.php?action=ranking`);
        const data = await res.json();
        
        if (data.success) {
            list.innerHTML = '';
            if (data.topics.length === 0) {
                 list.innerHTML = '<div style="padding:10px; color:#888;">暂无话题</div>';
                 return;
            }
            data.topics.forEach((t, index) => {
                const div = document.createElement('div');
                div.style.padding = '8px 0';
                div.style.borderBottom = '1px solid #f0f0f0';
                div.style.cursor = 'pointer';
                div.style.display = 'flex';
                div.style.justifyContent = 'space-between';
                div.onclick = () => loadMemoirs('', 0, t.id);
                
                let rankColor = '#666';
                if (index === 0) rankColor = '#e74c3c';
                if (index === 1) rankColor = '#e67e22';
                if (index === 2) rankColor = '#f1c40f';
                
                div.innerHTML = `
                    <div style="font-weight: 500;">
                        <span style="color: ${rankColor}; margin-right: 5px; font-weight: bold;">${index + 1}</span>
                        #${escapeHtml(t.name)}
                    </div>
                    <div style="font-size: 0.8rem; color: #888;">${t.usage_count}</div>
                `;
                list.appendChild(div);
            });
        } else {
             list.innerHTML = `<div style="padding:10px; color:red;">加载失败</div>`;
        }
    } catch (e) {
        console.error("Load topic ranking failed", e);
        list.innerHTML = '<div style="padding:10px; color:red;">加载错误</div>';
    }
}

async function loadAnnouncements() {
    const list = document.getElementById('announcement-list');
    if (!list) return;
    
    try {
        const res = await fetch(`${API_BASE}announcements.php?action=get_latest`);
        const data = await res.json();
        
        if (data.success) {
            list.innerHTML = '';
            if (data.announcements.length === 0) {
                 list.innerHTML = '<div style="padding:10px; color:#888;">暂无公告</div>';
                 return;
            }
            data.announcements.forEach(a => {
                const div = document.createElement('div');
                div.style.marginBottom = '15px';
                div.style.paddingBottom = '10px';
                div.style.borderBottom = '1px solid #f0f0f0';
                const titleHtml = a.title ? `<div style="font-weight: 600; margin-bottom: 5px;">${escapeHtml(a.title)}</div>` : '';
                div.innerHTML = `
                    ${titleHtml}
                    <div style="font-size: 0.95rem; white-space: pre-wrap;">${escapeHtml(a.content)}</div>
                    <div style="font-size: 0.8rem; color: #888; margin-top: 5px;">${new Date(a.created_at).toLocaleDateString()}</div>
                `;
                list.appendChild(div);
            });
        } else {
             list.innerHTML = `<div style="padding:10px; color:red;">加载失败</div>`;
        }
    } catch (e) {
        console.error("Load announcements failed", e);
        list.innerHTML = '<div style="padding:10px; color:red;">加载错误</div>';
    }
}

function updateFileName(input) {
    if(input.files && input.files[0]) {
        alert(`已选择图片: ${input.files[0].name}`);
    }
}

async function loadNotifications() {
    const list = document.getElementById('notification-list');
    if (!list) return;
    
    try {
        const res = await fetch(`${API_BASE}interactions.php?action=get_notifications`);
        if (!res.ok) {
            throw new Error('Network response was not ok');
        }
        const data = await res.json();
        
        if (data.success && data.notifications.length > 0) {
            list.innerHTML = '';
            
            // Only show the latest notification
            const latestNotification = data.notifications[0];
            const div = document.createElement('div');
            div.className = 'notification-item';
            const actionText = latestNotification.type === 'like' ? '赞了你的回忆' : '评论了你的回忆';
            div.innerHTML = `
                <div style="font-size: 0.9rem;"><strong>${escapeHtml(latestNotification.actor_name)}</strong> ${actionText}</div>
                <div style="font-size: 0.8rem; color: #666; margin-top: 5px;">"${escapeHtml(latestNotification.memoir_preview.substring(0, 20))}..."</div>
                <div style="font-size: 0.7rem; color: #999; margin-top: 5px;">${new Date(latestNotification.created_at).toLocaleString()}</div>
            `;
            list.appendChild(div);
            
            // Show how many more notifications there are
            if (data.notifications.length > 1) {
                const moreText = document.createElement('div');
                moreText.className = 'more-notifications';
                moreText.innerHTML = `还有 ${data.notifications.length - 1} 条动态，点击 <a href="notifications.html" style="color: var(--primary-color);">查看更多</a>`;
                list.appendChild(moreText);
            }
        } else if (data.notifications.length === 0) {
            list.innerHTML = '<div class="notification-item" style="text-align: center; color: #666;">暂无动态</div>';
        }
    } catch (error) {
        console.error('加载通知失败:', error);
        list.innerHTML = '<div class="notification-item" style="text-align: center; color: red;">加载失败，请稍后重试</div>';
    }
}

// Load notifications with pagination for the notifications list page
async function loadNotificationsList(page = 1) {
    const list = document.getElementById('notifications-list');
    const paginationContainer = document.getElementById('notifications-pagination');
    
    if (!list) return;
    
    list.innerHTML = '<div style="text-align: center; padding: 20px; color: #888;">加载中...</div>';
    
    try {
        const res = await fetch(`${API_BASE}interactions.php?action=get_notifications&page=${page}&limit=10`);
        const data = await res.json();
        
        if (data.success) {
            list.innerHTML = '';
            
            if (data.notifications.length === 0) {
                list.innerHTML = '<div style="text-align: center; padding: 20px; color: #888;">暂无通知</div>';
                if (paginationContainer) paginationContainer.innerHTML = '';
                return;
            }
            
            data.notifications.forEach(notification => {
                const div = document.createElement('div');
                div.className = 'notification-list-item';
                const actionText = notification.type === 'like' ? '赞了你的回忆' : '评论了你的回忆';
                const preview = notification.memoir_preview ? notification.memoir_preview.substring(0, 100) + (notification.memoir_preview.length > 100 ? '...' : '') : '';
                div.innerHTML = `
                    <div style="font-size: 0.95rem; margin-bottom: 5px;"><strong>${escapeHtml(notification.actor_name)}</strong> ${actionText}</div>
                    <div style="font-size: 0.85rem; color: #666; margin-bottom: 5px; line-height: 1.4; border-left: 3px solid #e9ecef; padding-left: 10px;">"${escapeHtml(preview)}"</div>
                    <div style="font-size: 0.75rem; color: #999;">${new Date(notification.created_at).toLocaleString()}</div>
                `;
                list.appendChild(div);
            });
            
            // Render pagination if we have the container
            if (paginationContainer) {
                renderNotificationsPagination(data.pagination);
            }
        } else {
            list.innerHTML = '<div style="text-align: center; padding: 20px; color: red;">加载失败</div>';
        }
    } catch (error) {
        console.error('加载通知失败:', error);
        list.innerHTML = '<div style="text-align: center; padding: 20px; color: red;">网络错误，请稍后重试</div>';
    }
}

// Render pagination for notifications list page
function renderNotificationsPagination(pagination) {
    const container = document.getElementById('notifications-pagination');
    if (!container) return;
    
    container.innerHTML = '';
    
    const { page, total_pages } = pagination;
    
    if (total_pages <= 1) return;
    
    // Previous button
    const prevBtn = document.createElement('button');
    prevBtn.className = 'page-btn';
    prevBtn.innerText = '上一页';
    prevBtn.disabled = page === 1;
    prevBtn.onclick = () => loadNotificationsList(page - 1);
    container.appendChild(prevBtn);
    
    // Page numbers
    for (let i = 1; i <= total_pages; i++) {
        if (i === 1 || i === total_pages || (i >= page - 2 && i <= page + 2)) {
            const btn = document.createElement('button');
            btn.className = `page-btn ${i === page ? 'active' : ''}`;
            btn.innerText = i;
            btn.onclick = () => loadNotificationsList(i);
            container.appendChild(btn);
        } else if (i === page - 3 || i === page + 3) {
            const span = document.createElement('span');
            span.innerText = '...';
            span.style.margin = '0 5px';
            container.appendChild(span);
        }
    }
    
    // Next button
    const nextBtn = document.createElement('button');
    nextBtn.className = 'page-btn';
    nextBtn.innerText = '下一页';
    nextBtn.disabled = page === total_pages;
    nextBtn.onclick = () => loadNotificationsList(page + 1);
    container.appendChild(nextBtn);
}

function renderProfileInfo() {
    const container = document.getElementById('my-profile-info');
    if (!container || !currentUser) return;
    
    container.innerHTML = `
        <div style="margin: 0 auto 15px; display: flex; justify-content: center;">
            ${getAvatarHtml(currentUser, '80px', '2rem')}
        </div>
        <div style="text-align: center;">
            <h3>${currentUser.name}</h3>
            <p style="color: #666;">@${currentUser.username}</p>
            <p style="margin-top: 10px;">${currentUser.class}</p>
            <p>${currentUser.phone}</p>
        </div>
    `;
}

// ==================== 私信未读数功能 ====================

// 启动私信未读数轮询
function startMessagePolling() {
    // 立即获取一次未读数
    fetchUnreadMessageCount();
    
    // 每10秒轮询一次
    if (messagePollingInterval) {
        clearInterval(messagePollingInterval);
    }
    messagePollingInterval = setInterval(() => {
        fetchUnreadMessageCount();
    }, 10000);
}

// 获取未读消息数
async function fetchUnreadMessageCount() {
    try {
        const res = await fetch(API_BASE + 'messages.php?action=get_unread_count');
        const data = await res.json();
        
        if (data.success && data.unread_count > 0) {
            updateMessageBadge(data.unread_count);
        } else {
            updateMessageBadge(0);
        }
    } catch (e) {
        console.error("Fetch unread count failed", e);
    }
}

// 更新私信角标
function updateMessageBadge(count) {
    unreadMessageCount = count;
    
    const navMessages = document.getElementById('nav-messages');
    if (!navMessages) return;
    
    // 移除旧的角标
    const oldBadge = navMessages.querySelector('.message-badge');
    if (oldBadge) {
        oldBadge.remove();
    }
    
    // 添加新角标
    if (count > 0) {
        const badge = document.createElement('span');
        badge.className = 'message-badge';
        badge.style.cssText = `
            background: #e74c3c;
            color: white;
            font-size: 0.7rem;
            min-width: 18px;
            height: 18px;
            border-radius: 9px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            position: absolute;
            top: -5px;
            right: -8px;
            padding: 0 5px;
        `;
        badge.textContent = count > 99 ? '99+' : count;
        navMessages.style.position = 'relative';
        navMessages.appendChild(badge);
    }
}
