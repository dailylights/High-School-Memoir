// Enhanced interactions: 收藏、分享、标签、二级评论、评论点赞、@提及

// 收藏/取消收藏
async function toggleFavorite(memoirId) {
    if (!currentUser) {
        alert('请先登录');
        return;
    }
    
    try {
        const formData = new FormData();
        formData.append('action', 'toggle_favorite');
        formData.append('memoir_id', memoirId);
        
        const res = await fetch(API_BASE + 'interactions.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-CSRF-Token': getCSRFToken() }
        });
        const data = await res.json();
        
        if (data.success) {
            // Update UI
            updateFavoriteButton(memoirId, data.favorited);
        } else {
            alert(data.message);
        }
    } catch (e) {
        console.error('Toggle favorite failed', e);
        alert('操作失败');
    }
}

// 更新收藏按钮状态
function updateFavoriteButton(memoirId, isFavorited) {
    // Find the favorite button in the memoir card
    const btn = document.querySelector(`[data-favorite-memoir="${memoirId}"]`);
    if (btn) {
        btn.classList.toggle('active', isFavorited);
        const countEl = btn.querySelector('.count');
        if (countEl) {
            let count = parseInt(countEl.textContent) || 0;
            countEl.textContent = isFavorited ? count + 1 : Math.max(0, count - 1);
        }
    }
}

// 分享回忆录
async function shareMemoir(memoirId, shareType = 'link', shareText = '') {
    if (!currentUser) {
        alert('请先登录');
        return;
    }
    
    try {
        const formData = new FormData();
        formData.append('action', 'share_memoir');
        formData.append('memoir_id', memoirId);
        formData.append('share_type', shareType);
        if (shareText) {
            formData.append('share_text', shareText);
        }
        
        const res = await fetch(API_BASE + 'interactions.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-CSRF-Token': getCSRFToken() }
        });
        const data = await res.json();
        
        if (data.success) {
            // Update share count
            updateShareCount(memoirId, data.share_count);
            
            if (shareType === 'link') {
                // Copy link to clipboard
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(data.share_url).then(() => {
                        showToast('链接已复制到剪贴板');
                    }).catch(() => {
                        prompt('复制链接:', data.share_url);
                    });
                } else {
                    prompt('复制链接:', data.share_url);
                }
            }
        } else {
            alert(data.message);
        }
    } catch (e) {
        console.error('Share failed', e);
        alert('分享失败');
    }
}

// 更新分享数
function updateShareCount(memoirId, count) {
    const btn = document.querySelector(`[data-share-memoir="${memoirId}"]`);
    if (btn) {
        const countEl = btn.querySelector('.count');
        if (countEl) {
            countEl.textContent = count;
        }
    }
}

// 显示分享菜单
function showShareMenu(memoirId, event) {
    event.stopPropagation();
    
    // Remove any existing share menu
    const existing = document.querySelector('.share-menu');
    if (existing) existing.remove();
    
    const menu = document.createElement('div');
    menu.className = 'share-menu';
    menu.style.cssText = `
        position: absolute;
        background: white;
        border-radius: 12px;
        box-shadow: 0 8px 30px rgba(0,0,0,0.15);
        padding: 8px;
        z-index: 1000;
        min-width: 180px;
    `;
    
    const shareOptions = [
        { type: 'link', icon: '🔗', label: '复制链接' },
        { type: 'wechat', icon: '💬', label: '微信' },
        { type: 'qq', icon: '🐧', label: 'QQ' },
        { type: 'weibo', icon: '🎯', label: '微博' },
    ];
    
    menu.innerHTML = shareOptions.map(opt => `
        <div style="padding: 10px 15px; cursor: pointer; border-radius: 8px; display: flex; align-items: center; gap: 10px; transition: background 0.2s;" 
             onmouseover="this.style.background='#f1f2f6'" 
             onmouseout="this.style.background='transparent'"
             onclick="shareMemoir(${memoirId}, '${opt.type}'); this.closest('.share-menu').remove();">
            <span style="font-size: 1.2rem;">${opt.icon}</span>
            <span style="font-size: 0.9rem; color: #2d3436;">${opt.label}</span>
        </div>
    `).join('');
    
    // Position near the button
    const rect = event.target.getBoundingClientRect();
    menu.style.top = (rect.bottom + window.scrollY + 5) + 'px';
    menu.style.left = (rect.left + window.scrollX - 100) + 'px';
    
    document.body.appendChild(menu);
    
    // Click outside to close
    setTimeout(() => {
        document.addEventListener('click', function closeMenu(e) {
            if (!menu.contains(e.target)) {
                menu.remove();
                document.removeEventListener('click', closeMenu);
            }
        });
    }, 10);
}

// 评论点赞/取消点赞
async function toggleCommentLike(commentId) {
    if (!currentUser) {
        alert('请先登录');
        return;
    }
    
    try {
        const formData = new FormData();
        formData.append('action', 'toggle_comment_like');
        formData.append('comment_id', commentId);
        
        const res = await fetch(API_BASE + 'interactions.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-CSRF-Token': getCSRFToken() }
        });
        const data = await res.json();
        
        if (data.success) {
            updateCommentLikeButton(commentId, data.liked);
        } else {
            alert(data.message);
        }
    } catch (e) {
        console.error('Toggle comment like failed', e);
        alert('操作失败');
    }
}

// 更新评论点赞按钮
function updateCommentLikeButton(commentId, isLiked) {
    const btn = document.querySelector(`[data-comment-like="${commentId}"]`);
    if (btn) {
        btn.classList.toggle('active', isLiked);
        const countEl = btn.querySelector('.count');
        if (countEl) {
            let count = parseInt(countEl.textContent) || 0;
            countEl.textContent = isLiked ? count + 1 : Math.max(0, count - 1);
        }
    }
}

// 回复评论
let currentReplyContext = { memoirId: null, commentId: null, replyToUserId: null, replyToName: '' };

function startReply(memoirId, commentId, replyToUserId, replyToName) {
    currentReplyContext = { memoirId, commentId, replyToUserId, replyToName };
    
    // Find the comment form or create a reply input
    const replyContainer = document.getElementById(`reply-container-${commentId}`);
    if (replyContainer) {
        replyContainer.style.display = 'flex';
        const input = replyContainer.querySelector('input');
        if (input) {
            input.placeholder = `回复 @${replyToName}...`;
            input.focus();
        }
    }
}

// 取消回复
function cancelReply(commentId) {
    currentReplyContext = { memoirId: null, commentId: null, replyToUserId: null, replyToName: '' };
    const replyContainer = document.getElementById(`reply-container-${commentId}`);
    if (replyContainer) {
        replyContainer.style.display = 'none';
    }
}

// 提交回复
async function submitReply(memoirId, parentCommentId) {
    if (!currentUser) {
        alert('请先登录');
        return;
    }
    
    const replyContainer = document.getElementById(`reply-container-${parentCommentId}`);
    if (!replyContainer) return;
    
    const input = replyContainer.querySelector('input');
    const content = input ? input.value.trim() : '';
    
    if (!content) {
        alert('请输入回复内容');
        return;
    }
    
    try {
        const formData = new FormData();
        formData.append('action', 'reply_comment');
        formData.append('memoir_id', memoirId);
        formData.append('parent_id', parentCommentId);
        formData.append('reply_to_user_id', currentReplyContext.replyToUserId || 0);
        formData.append('content', content);
        
        const res = await fetch(API_BASE + 'interactions.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-CSRF-Token': getCSRFToken() }
        });
        const data = await res.json();
        
        if (data.success) {
            input.value = '';
            cancelReply(parentCommentId);
            // Reload replies
            loadCommentReplies(memoirId, parentCommentId);
            // Update reply count
            updateReplyCount(parentCommentId, 1);
        } else {
            alert(data.message);
        }
    } catch (e) {
        console.error('Submit reply failed', e);
        alert('回复失败');
    }
}

// 更新回复数
function updateReplyCount(commentId, delta) {
    const countEl = document.querySelector(`[data-reply-count="${commentId}"]`);
    if (countEl) {
        let count = parseInt(countEl.textContent) || 0;
        countEl.textContent = Math.max(0, count + delta);
    }
}

// 加载评论回复
async function loadCommentReplies(memoirId, parentId) {
    const repliesContainer = document.getElementById(`replies-${parentId}`);
    if (!repliesContainer) return;
    
    try {
        const res = await fetch(`${API_BASE}interactions.php?action=get_comment_replies&parent_id=${parentId}&limit=50`);
        const data = await res.json();
        
        if (data.success) {
            if (data.replies.length === 0) {
                repliesContainer.innerHTML = '<div style="padding: 10px 0; color: #888; font-size: 0.85rem;">暂无回复</div>';
                return;
            }
            
            repliesContainer.innerHTML = '';
            data.replies.forEach(reply => {
                repliesContainer.appendChild(createReplyElement(memoirId, reply, parentId));
            });
        }
    } catch (e) {
        console.error('Load replies failed', e);
    }
}

// 创建回复元素
function createReplyElement(memoirId, reply, parentId) {
    const div = document.createElement('div');
    div.className = 'comment-reply';
    div.style.cssText = `
        display: flex;
        gap: 8px;
        padding: 10px 0;
        border-top: 1px solid #f0f0f0;
    `;
    
    const replyToText = reply.reply_to_name ? ` 回复 <span style="color: #6c5ce7;">@${escapeHtml(reply.reply_to_name)}</span>` : '';
    const likeClass = reply.is_liked ? 'active' : '';
    
    div.innerHTML = `
        ${getAvatarHtml({name: reply.author_name, avatar: reply.author_avatar}, '28px', '0.75rem')}
        <div style="flex: 1; min-width: 0;">
            <div style="font-size: 0.85rem; color: #555; margin-bottom: 3px;">
                <strong>${escapeHtml(reply.author_name)}</strong>${replyToText}
                <span style="font-size: 0.75rem; color: #aaa; margin-left: 8px;">${timeAgo(reply.created_at)}</span>
            </div>
            <div style="font-size: 0.85rem; line-height: 1.5;">${escapeHtml(reply.content)}</div>
            <div style="display: flex; gap: 15px; margin-top: 5px; font-size: 0.8rem; color: #888;">
                <span data-comment-like="${reply.id}" class="comment-like-btn ${likeClass}" 
                      onclick="toggleCommentLike(${reply.id})" 
                      style="cursor: pointer; display: flex; align-items: center; gap: 3px; transition: color 0.2s;">
                    👍 <span class="count">${reply.likes_count || 0}</span>
                </span>
                <span onclick="startReply(${memoirId}, ${parentId}, ${reply.user_id}, '${escapeHtml(reply.author_name)}')" 
                      style="cursor: pointer;">
                    💬 回复
                </span>
            </div>
        </div>
    `;
    
    return div;
}

// 切换回复显示
function toggleReplies(memoirId, commentId) {
    const repliesContainer = document.getElementById(`replies-${commentId}`);
    if (!repliesContainer) return;
    
    if (repliesContainer.style.display === 'none' || !repliesContainer.innerHTML) {
        repliesContainer.style.display = 'block';
        if (!repliesContainer.innerHTML) {
            loadCommentReplies(memoirId, commentId);
        }
    } else {
        repliesContainer.style.display = 'none';
    }
}

// 搜索用户用于@提及
async function searchUsersForMention(keyword) {
    if (!currentUser || !keyword) return [];
    
    try {
        const res = await fetch(`${API_BASE}interactions.php?action=search_users_for_mention&keyword=${encodeURIComponent(keyword)}`);
        const data = await res.json();
        return data.success ? data.users : [];
    } catch (e) {
        console.error('Search users failed', e);
        return [];
    }
}

// 显示@提及建议
let mentionAutocompleteState = { active: false, startPos: 0, currentKeyword: '' };

function setupMentionAutocomplete(inputElement) {
    inputElement.addEventListener('input', function(e) {
        const cursorPos = this.selectionStart;
        const text = this.value.substring(0, cursorPos);
        
        // Find last @ symbol
        const atIndex = text.lastIndexOf('@');
        
        if (atIndex !== -1 && (atIndex === 0 || text[atIndex - 1] === ' ' || text[atIndex - 1] === '\n')) {
            const keyword = text.substring(atIndex + 1);
            if (keyword.length >= 1 && !keyword.includes(' ')) {
                mentionAutocompleteState = { active: true, startPos: atIndex, currentKeyword: keyword };
                showMentionSuggestions(keyword, inputElement);
                return;
            }
        }
        
        mentionAutocompleteState.active = false;
        hideMentionSuggestions();
    });
    
    inputElement.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && mentionAutocompleteState.active) {
            hideMentionSuggestions();
            e.preventDefault();
        }
    });
}

// 显示@建议列表
async function showMentionSuggestions(keyword, inputElement) {
    const users = await searchUsersForMention(keyword);
    
    // Remove existing
    hideMentionSuggestions();
    
    if (users.length === 0) return;
    
    const suggestionBox = document.createElement('div');
    suggestionBox.id = 'mention-suggestions';
    suggestionBox.style.cssText = `
        position: absolute;
        background: white;
        border-radius: 8px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        padding: 5px;
        z-index: 1001;
        max-height: 200px;
        overflow-y: auto;
        min-width: 200px;
    `;
    
    users.slice(0, 6).forEach((user, index) => {
        const item = document.createElement('div');
        item.style.cssText = `
            padding: 8px 10px;
            cursor: pointer;
            border-radius: 6px;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: background 0.15s;
        `;
        item.onmouseover = () => item.style.background = '#f1f2f6';
        item.onmouseout = () => item.style.background = 'transparent';
        item.onclick = () => insertMention(user.name, inputElement);
        
        item.innerHTML = `
            ${getAvatarHtml({name: user.name, avatar: user.avatar}, '28px', '0.75rem')}
            <div>
                <div style="font-size: 0.85rem; font-weight: 500;">${escapeHtml(user.name)}</div>
                <div style="font-size: 0.75rem; color: #888;">${escapeHtml(user.class || '')}</div>
            </div>
        `;
        
        suggestionBox.appendChild(item);
    });
    
    // Position near input
    const rect = inputElement.getBoundingClientRect();
    suggestionBox.style.top = (rect.bottom + window.scrollY + 5) + 'px';
    suggestionBox.style.left = (rect.left + window.scrollX) + 'px';
    
    document.body.appendChild(suggestionBox);
}

// 隐藏@建议
function hideMentionSuggestions() {
    const el = document.getElementById('mention-suggestions');
    if (el) el.remove();
}

// 插入@提及
function insertMention(userName, inputElement) {
    const text = inputElement.value;
    const cursorPos = inputElement.selectionStart;
    
    // Find the @ position
    const beforeCursor = text.substring(0, cursorPos);
    const atIndex = beforeCursor.lastIndexOf('@');
    
    if (atIndex !== -1) {
        const newText = text.substring(0, atIndex) + '@' + userName + ' ' + text.substring(cursorPos);
        inputElement.value = newText;
        const newCursorPos = atIndex + userName.length + 2;
        inputElement.setSelectionRange(newCursorPos, newCursorPos);
    }
    
    hideMentionSuggestions();
    inputElement.focus();
}

// 获取热门标签
async function loadPopularTags(limit = 20) {
    try {
        const res = await fetch(`${API_BASE}interactions.php?action=get_popular_tags&limit=${limit}`);
        const data = await res.json();
        return data.success ? data.tags : [];
    } catch (e) {
        console.error('Load popular tags failed', e);
        return [];
    }
}

// 获取回忆录标签
async function getMemoirTags(memoirId) {
    try {
        const res = await fetch(`${API_BASE}interactions.php?action=get_memoir_tags&memoir_id=${memoirId}`);
        const data = await res.json();
        return data.success ? data.tags : [];
    } catch (e) {
        console.error('Get memoir tags failed', e);
        return [];
    }
}

// 渲染标签HTML
function renderTagsHtml(tags, isSmall = false) {
    if (!tags || tags.length === 0) return '';
    
    const sizeClass = isSmall 
        ? 'font-size: 0.75rem; padding: 3px 10px;' 
        : 'font-size: 0.85rem; padding: 5px 14px;';
    
    return '<div style="display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px;">' +
        tags.map(tag => `
            <span style="background: rgba(108, 92, 231, 0.1); color: #6c5ce7; border-radius: 15px; ${sizeClass} cursor: pointer; transition: background 0.2s;" 
                  onmouseover="this.style.background='rgba(108,92,231,0.2)'"
                  onmouseout="this.style.background='rgba(108,92,231,0.1)'"
                  onclick="window.location.href='search.html?q=%23${encodeURIComponent(tag.name)}'">
                #${escapeHtml(tag.name)}
            </span>
        `).join('') +
    '</div>';
}

// 时间格式化
function timeAgo(dateStr) {
    const date = new Date(dateStr);
    const now = new Date();
    const diff = (now - date) / 1000;
    
    if (diff < 60) return '刚刚';
    if (diff < 3600) return Math.floor(diff / 60) + '分钟前';
    if (diff < 86400) return Math.floor(diff / 3600) + '小时前';
    if (diff < 604800) return Math.floor(diff / 86400) + '天前';
    
    return date.toLocaleDateString();
}

// Toast 提示
function showToast(message, duration = 2000) {
    const toast = document.createElement('div');
    toast.style.cssText = `
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        background: rgba(0, 0, 0, 0.8);
        color: white;
        padding: 12px 24px;
        border-radius: 8px;
        z-index: 9999;
        font-size: 0.9rem;
        animation: toastFadeIn 0.3s ease-out;
    `;
    toast.textContent = message;
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.style.animation = 'toastFadeOut 0.3s ease-out';
        setTimeout(() => toast.remove(), 300);
    }, duration);
}

// 添加CSS动画
const style = document.createElement('style');
style.textContent = `
    @keyframes toastFadeIn {
        from { opacity: 0; transform: translate(-50%, -50%) scale(0.9); }
        to { opacity: 1; transform: translate(-50%, -50%) scale(1); }
    }
    @keyframes toastFadeOut {
        from { opacity: 1; }
        to { opacity: 0; }
    }
    .comment-like-btn.active {
        color: #e74c3c !important;
    }
`;
document.head.appendChild(style);

// Close share menu and mention suggestions when clicking elsewhere
document.addEventListener('click', function(e) {
    const shareMenu = document.querySelector('.share-menu');
    if (shareMenu && !shareMenu.contains(e.target) && !e.target.closest('[data-share-memoir]')) {
        shareMenu.remove();
    }
    
    const mentionBox = document.getElementById('mention-suggestions');
    if (mentionBox && !mentionBox.contains(e.target)) {
        // Don't hide if clicking the input
        if (!e.target.matches('input, textarea')) {
            hideMentionSuggestions();
        }
    }
});
