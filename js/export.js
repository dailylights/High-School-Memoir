let exportData = null;
let exportConfig = {
    format: 'pdf',
    includeImages: true,
    includeComments: false,
    title: '',
    subtitle: ''
};

function openExportModal(type = 'my', id = null) {
    if (!currentUser) {
        alert('请先登录');
        window.location.href = 'login.html';
        return;
    }
    
    const modal = document.getElementById('export-modal');
    if (!modal) {
        createExportModal();
    }
    
    exportConfig.type = type;
    exportConfig.id = id;
    
    loadExportPreview();
    document.getElementById('export-modal').style.display = 'flex';
}

function createExportModal() {
    const modalHtml = `
    <div class="modal-overlay" id="export-modal" style="z-index: 3000;">
        <div class="modal-content" style="max-width: 700px; width: 90%;">
            <div class="modal-header">
                <h2 style="color: var(--primary-color); margin: 0;">📖 导出回忆录</h2>
                <button class="modal-close" onclick="closeExportModal()">&times;</button>
            </div>
            
            <div class="export-progress" id="export-loading" style="display: none;">
                <div class="loading-spinner"></div>
                <p id="export-loading-text">正在加载回忆录数据...</p>
            </div>
            
            <div id="export-content">
                <div class="export-options">
                    <div class="export-section">
                        <h3>📄 导出格式</h3>
                        <div class="format-options">
                            <div class="format-card active" data-format="pdf" onclick="selectFormat('pdf')">
                                <div class="format-icon">📕</div>
                                <div class="format-name">PDF 电子书</div>
                                <div class="format-desc">精美排版，适合打印分享</div>
                            </div>
                            <div class="format-card" data-format="txt" onclick="selectFormat('txt')">
                                <div class="format-icon">📝</div>
                                <div class="format-name">TXT 文本</div>
                                <div class="format-desc">纯文本，通用兼容</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="export-section">
                        <h3>⚙️ 导出选项</h3>
                        <div class="option-list">
                            <label class="option-item">
                                <input type="checkbox" id="opt-images" checked onchange="updateExportConfig()">
                                <span>包含图片</span>
                            </label>
                            <label class="option-item">
                                <input type="checkbox" id="opt-comments" onchange="updateExportConfig()">
                                <span>包含评论</span>
                            </label>
                        </div>
                    </div>
                    
                    <div class="export-section">
                        <h3>📋 导出预览</h3>
                        <div id="export-preview" class="export-preview">
                            <div style="text-align: center; color: #888; padding: 30px;">加载中...</div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-actions" style="margin-top: 25px;">
                    <button class="btn btn-secondary" onclick="closeExportModal()" style="padding: 12px 30px;">取消</button>
                    <button class="btn btn-primary" onclick="startExport()" style="padding: 12px 30px;" id="export-btn">
                        📥 开始导出
                    </button>
                </div>
            </div>
        </div>
    </div>
    `;
    
    const styleHtml = `
    <style>
        .export-options { max-height: 60vh; overflow-y: auto; }
        .export-section { margin-bottom: 25px; }
        .export-section h3 { margin: 0 0 15px 0; font-size: 1.1rem; color: #333; }
        .format-options { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .format-card { border: 2px solid #e0e0e0; border-radius: 12px; padding: 20px; text-align: center; cursor: pointer; transition: all 0.3s; background: white; }
        .format-card:hover { border-color: var(--primary-color); transform: translateY(-2px); box-shadow: 0 4px 12px rgba(108,92,231,0.15); }
        .format-card.active { border-color: var(--primary-color); background: linear-gradient(135deg, rgba(108,92,231,0.08), rgba(160,148,255,0.08)); }
        .format-icon { font-size: 2.5rem; margin-bottom: 10px; }
        .format-name { font-weight: 600; font-size: 1rem; margin-bottom: 5px; color: #333; }
        .format-desc { font-size: 0.85rem; color: #888; }
        .option-list { display: flex; flex-direction: column; gap: 10px; }
        .option-item { display: flex; align-items: center; gap: 10px; cursor: pointer; padding: 8px 12px; border-radius: 8px; transition: background 0.2s; }
        .option-item:hover { background: #f5f5f5; }
        .option-item input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; accent-color: var(--primary-color); }
        .export-preview { background: #fafafa; border-radius: 12px; padding: 20px; max-height: 250px; overflow-y: auto; border: 1px solid #eee; }
        .preview-item { padding: 12px; background: white; border-radius: 8px; margin-bottom: 10px; border-left: 3px solid var(--primary-color); }
        .preview-item:last-child { margin-bottom: 0; }
        .preview-title { font-weight: 600; color: #333; margin-bottom: 5px; font-size: 0.95rem; }
        .preview-meta { font-size: 0.8rem; color: #888; }
        .export-progress { text-align: center; padding: 40px 20px; }
        .loading-spinner { width: 50px; height: 50px; border: 3px solid #f0f0f0; border-top-color: var(--primary-color); border-radius: 50%; margin: 0 auto 15px; animation: spin 1s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        
        .pdf-page { background: white; padding: 40px; margin: 20px auto; box-shadow: 0 2px 10px rgba(0,0,0,0.1); max-width: 800px; }
        .pdf-cover { text-align: center; padding: 60px 40px; background: linear-gradient(135deg, #6c5ce7, #a29bfe); color: white; border-radius: 8px; }
        .pdf-cover h1 { font-size: 2rem; margin-bottom: 10px; }
        .pdf-cover .author { font-size: 1.1rem; opacity: 0.9; }
        .pdf-cover .date { margin-top: 20px; font-size: 0.9rem; opacity: 0.8; }
        .pdf-toc { padding: 30px 0; border-bottom: 1px solid #eee; }
        .pdf-toc h2 { color: var(--primary-color); margin-bottom: 20px; }
        .pdf-toc-item { padding: 8px 0; border-bottom: 1px dotted #ddd; display: flex; justify-content: space-between; }
        .pdf-memoir { padding: 25px 0; border-bottom: 1px solid #f0f0f0; }
        .pdf-memoir:last-child { border-bottom: none; }
        .pdf-memoir-header { display: flex; align-items: center; gap: 12px; margin-bottom: 15px; }
        .pdf-memoir-avatar { width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #6c5ce7, #a29bfe); display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; }
        .pdf-memoir-info { flex: 1; }
        .pdf-memoir-author { font-weight: 600; color: #333; }
        .pdf-memoir-time { font-size: 0.85rem; color: #888; }
        .pdf-memoir-content { line-height: 1.8; color: #444; white-space: pre-wrap; }
        .pdf-memoir-images { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 15px; }
        .pdf-memoir-images img { max-width: 200px; max-height: 150px; border-radius: 8px; object-fit: cover; }
        .pdf-memoir-topic { display: inline-block; background: #f0edff; color: var(--primary-color); padding: 2px 10px; border-radius: 10px; font-size: 0.85rem; margin-bottom: 10px; }
        .pdf-memoir-stats { margin-top: 10px; color: #999; font-size: 0.85rem; display: flex; gap: 20px; }
        .pdf-comments { margin-top: 15px; padding: 15px; background: #f9f9f9; border-radius: 8px; }
        .pdf-comments h4 { margin: 0 0 10px 0; color: #666; font-size: 0.9rem; }
        .pdf-comment { padding: 8px 0; border-bottom: 1px solid #eee; font-size: 0.9rem; }
        .pdf-comment:last-child { border-bottom: none; }
        .pdf-comment-author { font-weight: 600; color: #555; }
        .pdf-footer { text-align: center; padding: 20px; color: #aaa; font-size: 0.85rem; border-top: 1px solid #eee; margin-top: 30px; }
    </style>
    `;
    
    document.head.insertAdjacentHTML('beforeend', styleHtml);
    document.body.insertAdjacentHTML('beforeend', modalHtml);
}

function selectFormat(format) {
    exportConfig.format = format;
    document.querySelectorAll('.format-card').forEach(card => {
        card.classList.toggle('active', card.dataset.format === format);
    });
}

function updateExportConfig() {
    exportConfig.includeImages = document.getElementById('opt-images').checked;
    exportConfig.includeComments = document.getElementById('opt-comments').checked;
}

function closeExportModal() {
    const modal = document.getElementById('export-modal');
    if (modal) {
        modal.style.display = 'none';
    }
}

async function loadExportPreview() {
    const preview = document.getElementById('export-preview');
    const loading = document.getElementById('export-loading');
    const content = document.getElementById('export-content');
    
    content.style.display = 'none';
    loading.style.display = 'block';
    document.getElementById('export-loading-text').textContent = '正在加载回忆录数据...';
    
    try {
        let url = API_BASE + 'memoirs.php?action=export';
        if (exportConfig.type === 'memoir' && exportConfig.id) {
            url += '&memoir_id=' + exportConfig.id;
        } else if (exportConfig.type === 'class' && exportConfig.id) {
            url += '&class_id=' + exportConfig.id;
        } else if (exportConfig.type === 'user' && exportConfig.id) {
            url += '&user_id=' + exportConfig.id;
        }
        
        const res = await fetch(url);
        const data = await res.json();
        
        if (!data.success) {
            preview.innerHTML = `<div style="color: #e74c3c; text-align: center; padding: 20px;">${data.message}</div>`;
            document.getElementById('export-btn').disabled = true;
            return;
        }
        
        exportData = data;
        
        const count = data.memoirs.length;
        if (data.user) {
            exportConfig.title = `${data.user.name}的回忆录`;
            exportConfig.subtitle = data.user.class || '';
        } else if (exportConfig.type === 'memoir') {
            exportConfig.title = '单篇回忆录';
            exportConfig.subtitle = data.memoirs[0]?.author_name || '';
        } else if (exportConfig.type === 'class') {
            exportConfig.title = '班级回忆录合集';
            exportConfig.subtitle = '全体同学的珍贵回忆';
        } else {
            exportConfig.title = '回忆录合集';
            exportConfig.subtitle = '';
        }
        
        preview.innerHTML = `
            <div style="margin-bottom: 10px; color: #666; font-weight: 500;">
                共 ${count} 篇回忆录
            </div>
            ${data.memoirs.slice(0, 5).map(m => `
                <div class="preview-item">
                    <div class="preview-title">${escapeHtml(m.content.substring(0, 50) + (m.content.length > 50 ? '...' : ''))}</div>
                    <div class="preview-meta">
                        ${new Date(m.created_at).toLocaleDateString()}
                        ${m.topic_name ? ` · #${m.topic_name}` : ''}
                        ${m.images && m.images.length > 0 ? ` · 📷 ${m.images.length}张图` : ''}
                    </div>
                </div>
            `).join('')}
            ${count > 5 ? `<div style="text-align: center; color: #888; padding: 10px;">...还有 ${count - 5} 篇</div>` : ''}
        `;
        
        document.getElementById('export-btn').disabled = false;
        
    } catch (e) {
        console.error("Load export data failed", e);
        preview.innerHTML = '<div style="color: #e74c3c; text-align: center; padding: 20px;">加载失败，请稍后重试</div>';
    } finally {
        content.style.display = 'block';
        loading.style.display = 'none';
    }
}

async function startExport() {
    if (!exportData || exportData.memoirs.length === 0) {
        alert('没有可导出的回忆录');
        return;
    }
    
    const btn = document.getElementById('export-btn');
    btn.disabled = true;
    btn.innerHTML = '⏳ 正在生成...';
    
    try {
        if (exportConfig.format === 'pdf') {
            await exportAsPDF();
        } else {
            exportAsTXT();
        }
    } catch (e) {
        console.error("Export failed", e);
        alert('导出失败，请稍后重试');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '📥 开始导出';
    }
}

function exportAsTXT() {
    const memoirs = exportData.memoirs;
    let content = '';
    
    content += '═'.repeat(50) + '\n';
    content += '  ' + exportConfig.title + '\n';
    if (exportConfig.subtitle) {
        content += '  ' + exportConfig.subtitle + '\n';
    }
    content += '  生成时间：' + new Date().toLocaleString() + '\n';
    content += '═'.repeat(50) + '\n\n';
    
    content += '【目 录】\n';
    memoirs.forEach((m, i) => {
        const date = new Date(m.created_at).toLocaleDateString();
        const preview = m.content.substring(0, 30).replace(/\n/g, ' ');
        content += `${i + 1}. [${date}] ${preview}...\n`;
    });
    content += '\n' + '─'.repeat(50) + '\n\n';
    
    memoirs.forEach((m, i) => {
        content += `【第 ${i + 1} 篇】\n`;
        content += `────────────────────────────────────────\n`;
        content += `作者：${m.author_name}\n`;
        content += `时间：${new Date(m.created_at).toLocaleString()}\n`;
        if (m.topic_name) {
            content += `话题：#${m.topic_name}\n`;
        }
        if (m.images && m.images.length > 0 && exportConfig.includeImages) {
            content += `图片：${m.images.length} 张\n`;
        }
        content += `────────────────────────────────────────\n\n`;
        content += m.content + '\n\n';
        
        if (m.comments && m.comments.length > 0 && exportConfig.includeComments) {
            content += `── 评论区 ──\n`;
            m.comments.forEach(c => {
                content += `${c.author_name}：${c.content}\n`;
            });
            content += '\n';
        }
        
        content += `点赞：${m.likes_count}    评论：${m.comments ? m.comments.length : 0}\n\n`;
    });
    
    content += '\n' + '═'.repeat(50) + '\n';
    content += '  本书由「高中回忆录」自动生成\n';
    content += '  ' + memoirs.length + ' 篇珍贵回忆\n';
    content += '═'.repeat(50) + '\n';
    
    downloadFile(content, `${exportConfig.title}.txt`, 'text/plain;charset=utf-8');
}

async function exportAsPDF() {
    const loading = document.getElementById('export-loading');
    const content = document.getElementById('export-content');
    
    content.style.display = 'none';
    loading.style.display = 'block';
    document.getElementById('export-loading-text').textContent = '正在生成PDF，请稍候...';
    
    await loadScript('https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js');
    await loadScript('https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js');
    
    const { jsPDF } = window.jspdf;
    const pdf = new jsPDF('p', 'mm', 'a4');
    const pageWidth = pdf.internal.pageSize.getWidth();
    const pageHeight = pdf.internal.pageSize.getHeight();
    const margin = 20;
    const contentWidth = pageWidth - margin * 2;
    
    let yPos = margin;
    
    pdf.setFont('helvetica');
    
    yPos = addPDFFirstPage(pdf, pageWidth, pageHeight, margin, yPos);
    
    const memoirs = exportData.memoirs;
    
    for (let i = 0; i < memoirs.length; i++) {
        const m = memoirs[i];
        
        if (yPos > pageHeight - 40) {
            pdf.addPage();
            yPos = margin;
        }
        
        yPos = await addPDFMemoir(pdf, m, i + 1, margin, contentWidth, pageHeight, yPos);
    }
    
    pdf.save(`${exportConfig.title}.pdf`);
    
    content.style.display = 'block';
    loading.style.display = 'none';
    closeExportModal();
}

function addPDFFirstPage(pdf, pageWidth, pageHeight, margin, yPos) {
    const memoirs = exportData.memoirs;
    const user = exportData.user;
    
    pdf.setFillColor(108, 92, 231);
    pdf.rect(0, 0, pageWidth, 60, 'F');
    
    pdf.setTextColor(255, 255, 255);
    pdf.setFontSize(24);
    pdf.setFont('helvetica', 'bold');
    
    const title = exportConfig.title || '我的回忆录';
    const titleWidth = pdf.getTextWidth(title);
    pdf.text(title, (pageWidth - titleWidth) / 2, 35);
    
    pdf.setFontSize(12);
    pdf.setFont('helvetica', 'normal');
    if (exportConfig.subtitle) {
        const subWidth = pdf.getTextWidth(exportConfig.subtitle);
        pdf.text(exportConfig.subtitle, (pageWidth - subWidth) / 2, 50);
    }
    
    yPos = 75;
    
    pdf.setTextColor(100, 100, 100);
    pdf.setFontSize(10);
    const dateStr = '生成时间：' + new Date().toLocaleDateString();
    const dateWidth = pdf.getTextWidth(dateStr);
    pdf.text(dateStr, (pageWidth - dateWidth) / 2, yPos);
    
    yPos += 15;
    
    pdf.setDrawColor(230, 230, 230);
    pdf.line(margin, yPos, pageWidth - margin, yPos);
    yPos += 10;
    
    pdf.setTextColor(108, 92, 231);
    pdf.setFontSize(16);
    pdf.setFont('helvetica', 'bold');
    pdf.text('目  录', margin, yPos);
    yPos += 12;
    
    pdf.setTextColor(80, 80, 80);
    pdf.setFontSize(11);
    pdf.setFont('helvetica', 'normal');
    
    for (let i = 0; i < Math.min(memoirs.length, 20); i++) {
        if (yPos > pageHeight - 30) {
            pdf.addPage();
            yPos = margin;
        }
        
        const m = memoirs[i];
        const date = new Date(m.created_at).toLocaleDateString();
        const preview = m.content.substring(0, 40).replace(/\n/g, ' ');
        const num = String(i + 1).padStart(2, '0');
        
        pdf.setFont('helvetica', 'bold');
        pdf.text(`${num}.`, margin, yPos);
        pdf.setFont('helvetica', 'normal');
        pdf.text(`${preview}...`, margin + 12, yPos);
        pdf.setTextColor(160, 160, 160);
        pdf.text(date, pageWidth - margin - 25, yPos);
        pdf.setTextColor(80, 80, 80);
        
        yPos += 8;
    }
    
    if (memoirs.length > 20) {
        yPos += 5;
        pdf.setTextColor(160, 160, 160);
        pdf.text(`...还有 ${memoirs.length - 20} 篇回忆录`, margin, yPos);
    }
    
    pdf.addPage();
    return margin;
}

async function addPDFMemoir(pdf, m, index, margin, contentWidth, pageHeight, yPos) {
    const lineHeight = 7;
    
    pdf.setFillColor(245, 243, 255);
    pdf.roundedRect(margin, yPos - 2, contentWidth, 14, 3, 3, 'F');
    
    pdf.setTextColor(108, 92, 231);
    pdf.setFontSize(12);
    pdf.setFont('helvetica', 'bold');
    pdf.text(`第 ${index} 篇`, margin + 5, yPos + 7);
    
    const date = new Date(m.created_at).toLocaleDateString();
    pdf.setTextColor(150, 150, 150);
    pdf.setFontSize(10);
    pdf.setFont('helvetica', 'normal');
    pdf.text(date, pageWidth - margin - 30, yPos + 7);
    
    yPos += 22;
    
    pdf.setTextColor(60, 60, 60);
    pdf.setFontSize(11);
    
    const lines = pdf.splitTextToSize(m.content, contentWidth);
    for (let i = 0; i < lines.length; i++) {
        if (yPos > pageHeight - 20) {
            pdf.addPage();
            yPos = margin;
        }
        pdf.text(lines[i], margin, yPos);
        yPos += lineHeight;
    }
    
    yPos += 3;
    
    if (m.topic_name) {
        if (yPos > pageHeight - 20) {
            pdf.addPage();
            yPos = margin;
        }
        pdf.setTextColor(108, 92, 231);
        pdf.setFontSize(10);
        pdf.text(`#${m.topic_name}`, margin, yPos);
        yPos += 10;
    }
    
    if (exportConfig.includeImages && m.images && m.images.length > 0) {
        const imgSize = 40;
        const gap = 5;
        const perRow = Math.floor(contentWidth / (imgSize + gap));
        
        for (let i = 0; i < Math.min(m.images.length, 6); i++) {
            if (yPos + imgSize > pageHeight - 20) {
                pdf.addPage();
                yPos = margin;
            }
            
            const col = i % perRow;
            const row = Math.floor(i / perRow);
            const imgX = margin + col * (imgSize + gap);
            const imgY = yPos + row * (imgSize + gap);
            
            try {
                const imgData = await loadImageBase64(m.images[i]);
                if (imgData) {
                    pdf.addImage(imgData, 'JPEG', imgX, imgY, imgSize, imgSize);
                }
            } catch (e) {
                pdf.setDrawColor(220, 220, 220);
                pdf.setFillColor(250, 250, 250);
                pdf.roundedRect(imgX, imgY, imgSize, imgSize, 3, 3, 'FD');
            }
        }
        
        const rows = Math.ceil(Math.min(m.images.length, 6) / perRow);
        yPos += rows * (imgSize + gap) + 5;
    }
    
    if (exportConfig.includeComments && m.comments && m.comments.length > 0) {
        yPos += 3;
        if (yPos > pageHeight - 20) {
            pdf.addPage();
            yPos = margin;
        }
        
        pdf.setTextColor(120, 120, 120);
        pdf.setFontSize(10);
        pdf.setFont('helvetica', 'bold');
        pdf.text(`💬 ${m.comments.length} 条评论`, margin, yPos);
        yPos += 8;
        
        pdf.setDrawColor(240, 240, 240);
        for (let i = 0; i < Math.min(m.comments.length, 5); i++) {
            if (yPos > pageHeight - 20) {
                pdf.addPage();
                yPos = margin;
            }
            
            const c = m.comments[i];
            pdf.setFont('helvetica', 'bold');
            pdf.setTextColor(100, 100, 100);
            pdf.text(`${c.author_name}：`, margin + 5, yPos);
            
            const authorWidth = pdf.getTextWidth(`${c.author_name}：`);
            pdf.setFont('helvetica', 'normal');
            pdf.setTextColor(80, 80, 80);
            const commentLines = pdf.splitTextToSize(c.content, contentWidth - authorWidth - 10);
            for (let j = 0; j < commentLines.length; j++) {
                if (yPos > pageHeight - 20) {
                    pdf.addPage();
                    yPos = margin;
                }
                pdf.text(commentLines[j], margin + 5 + (j === 0 ? authorWidth : 0), yPos);
                yPos += 6;
            }
            
            yPos += 2;
        }
    }
    
    yPos += 5;
    pdf.setDrawColor(240, 240, 240);
    pdf.line(margin, yPos, pageWidth - margin, yPos);
    yPos += 10;
    
    return yPos;
}

function loadImageBase64(src) {
    return new Promise((resolve) => {
        const img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = function() {
            const canvas = document.createElement('canvas');
            canvas.width = img.width;
            canvas.height = img.height;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(img, 0, 0);
            try {
                resolve(canvas.toDataURL('image/jpeg', 0.7));
            } catch (e) {
                resolve(null);
            }
        };
        img.onerror = () => resolve(null);
        img.src = src;
    });
}

function loadScript(src) {
    return new Promise((resolve, reject) => {
        if (src.includes('jspdf') && window.jspdf) {
            resolve();
            return;
        }
        if (src.includes('html2canvas') && window.html2canvas) {
            resolve();
            return;
        }
        
        const script = document.createElement('script');
        script.src = src;
        script.onload = resolve;
        script.onerror = reject;
        document.head.appendChild(script);
    });
}

function downloadFile(content, filename, type) {
    const blob = new Blob([content], { type: type });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

document.addEventListener('click', function(event) {
    const modal = document.getElementById('export-modal');
    if (modal && event.target == modal) {
        closeExportModal();
    }
});
