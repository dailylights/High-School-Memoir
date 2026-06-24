// Follow system JavaScript functions

// Load follow stats for a user
async function loadFollowStats(userId) {
    try {
        const res = await fetch(`${API_BASE}follows.php?action=get_stats&user_id=${userId}`);
        const data = await res.json();
        
        if (data.success) {
            return {
                following: data.following_count,
                followers: data.follower_count,
                special: data.special_count
            };
        }
    } catch (e) {
        console.error('Load follow stats failed', e);
    }
    return { following: 0, followers: 0, special: 0 };
}

// Initialize follow stats on profile page
async function initProfileFollowStats() {
    if (!currentUser) return;
    
    const stats = await loadFollowStats(currentUser.id);
    
    const followingEl = document.getElementById('following-count');
    const followersEl = document.getElementById('followers-count');
    const specialEl = document.getElementById('special-count');
    
    if (followingEl) followingEl.textContent = stats.following;
    if (followersEl) followersEl.textContent = stats.followers;
    if (specialEl) specialEl.textContent = stats.special;
}

// Follow a user
async function followUser(userId, isSpecial = false) {
    if (!currentUser) {
        alert('请先登录');
        return { success: false, message: '请先登录' };
    }

    try {
        const formData = new FormData();
        formData.append('user_id', userId);
        if (isSpecial) {
            formData.append('is_special', 1);
        }
        
        const res = await fetch(`${API_BASE}follows.php?action=follow`, {
            method: 'POST',
            body: formData,
            headers: { 'X-CSRF-Token': getCSRFToken() }
        });
        const data = await res.json();
        return data;
    } catch (e) {
        console.error('Follow failed', e);
        return { success: false, message: '操作失败' };
    }
}

// Unfollow a user
async function unfollowUser(userId) {
    if (!currentUser) {
        alert('请先登录');
        return { success: false, message: '请先登录' };
    }

    try {
        const formData = new FormData();
        formData.append('user_id', userId);
        
        const res = await fetch(`${API_BASE}follows.php?action=unfollow`, {
            method: 'POST',
            body: formData,
            headers: { 'X-CSRF-Token': getCSRFToken() }
        });
        const data = await res.json();
        return data;
    } catch (e) {
        console.error('Unfollow failed', e);
        return { success: false, message: '操作失败' };
    }
}

// Toggle special follow
async function toggleSpecialFollow(userId) {
    if (!currentUser) {
        alert('请先登录');
        return { success: false, message: '请先登录' };
    }

    try {
        const formData = new FormData();
        formData.append('user_id', userId);
        
        const res = await fetch(`${API_BASE}follows.php?action=toggle_special`, {
            method: 'POST',
            body: formData,
            headers: { 'X-CSRF-Token': getCSRFToken() }
        });
        const data = await res.json();
        return data;
    } catch (e) {
        console.error('Toggle special failed', e);
        return { success: false, message: '操作失败' };
    }
}

// Get follow status for current user
async function getFollowStatus(userId) {
    try {
        const res = await fetch(`${API_BASE}follows.php?action=get_status&user_id=${userId}`);
        const data = await res.json();
        return data;
    } catch (e) {
        console.error('Get follow status failed', e);
        return { success: false, is_following: false, is_special: false };
    }
}

// Render follow button for user cards
function renderFollowButton(userId, isFollowing, isSpecial, containerId) {
    const container = document.getElementById(containerId);
    if (!container || !currentUser || currentUser.id == userId) return;

    let html = '';
    if (isFollowing) {
        html = `
            <button class="follow-btn following" onclick="handleUnfollow(${userId})">已关注</button>
            <button class="special-btn ${isSpecial ? 'active' : ''}" onclick="handleToggleSpecial(${userId})" title="${isSpecial ? '取消特别关注' : '设为特别关注'}">🔥</button>
        `;
    } else {
        html = `
            <button class="follow-btn not-following" onclick="handleFollow(${userId})">关注</button>
        `;
    }
    container.innerHTML = html;
}

// Handle follow action
async function handleFollow(userId) {
    const result = await followUser(userId);
    if (result.success) {
        // Refresh the page or update UI
        if (typeof onFollowStateChanged === 'function') {
            onFollowStateChanged(userId, true);
        } else {
            location.reload();
        }
    } else {
        alert(result.message);
    }
}

// Handle unfollow action
async function handleUnfollow(userId) {
    if (!confirm('确定要取消关注吗？')) return;
    
    const result = await unfollowUser(userId);
    if (result.success) {
        if (typeof onFollowStateChanged === 'function') {
            onFollowStateChanged(userId, false);
        } else {
            location.reload();
        }
    } else {
        alert(result.message);
    }
}

// Handle toggle special action
async function handleToggleSpecial(userId) {
    const result = await toggleSpecialFollow(userId);
    if (result.success) {
        if (typeof onFollowStateChanged === 'function') {
            onFollowStateChanged(userId, true, result.is_special);
        } else {
            location.reload();
        }
    } else {
        alert(result.message);
    }
}

// Get following IDs for current user (for feed filtering)
let cachedFollowingIds = null;
let cachedSpecialIds = null;

async function getFollowingIds() {
    if (!currentUser) {
        return { ids: [], specialIds: [] };
    }

    // Return cache if available
    if (cachedFollowingIds !== null) {
        return { ids: cachedFollowingIds, specialIds: cachedSpecialIds };
    }

    try {
        const res = await fetch(`${API_BASE}follows.php?action=get_following_ids`);
        const data = await res.json();
        
        if (data.success) {
            cachedFollowingIds = data.ids;
            cachedSpecialIds = data.special_ids;
            return { ids: data.ids, specialIds: data.special_ids };
        }
    } catch (e) {
        console.error('Get following IDs failed', e);
    }
    
    return { ids: [], specialIds: [] };
}

// Clear cache (call when follow state changes)
function clearFollowingCache() {
    cachedFollowingIds = null;
    cachedSpecialIds = null;
}

// Callback when follow state changes - reload current page
function onFollowStateChanged(userId, isFollowing, isSpecial = false) {
    clearFollowingCache();
    // Reload current page to reflect changes
    location.reload();
}
