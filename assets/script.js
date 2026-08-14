/*
=========================================================
SwiftPOS 公共脚本库 - 硬件解耦与数据导出版 (沙箱式物理热敏重构)
=========================================================
*/

/* 0. 客户端语言环境自适应翻译助手 */
function getCookie(name) {
    const value = `; ${document.cookie}`;
    const parts = value.split(`; ${name}=`);
    if (parts.length === 2) return parts.pop().split(';').shift();
    return '';
}

const jsAlerts = {
    en: {
        excel_err: "Excel plugin failed to load. Please check your network connection.",
        pdf_err: "PDF plugin failed to load. Please check your network connection."
    },
    zh: {
        excel_err: "Excel 插件加载失败，请检查网络连接。",
        pdf_err: "PDF 插件加载失败，请检查网络连接。",
    },
    ms: {
        excel_err: "Plugin Excel gagal dimuat. Sila periksa sambungan rangkaian anda.",
        pdf_err: "Plugin PDF gagal dimuat. Sila periksa sambungan rangkaian anda."
    }
};

function getJsTranslation(key) {
    const lang = getCookie('lang') || 'en';
    const langDict = jsAlerts[lang] || jsAlerts['en'];
    return langDict[key] || '';
}

/* 1. 密码可见性切换 */
function togglePasswordVisibility() {
    const pwdInput = document.getElementById('auth_password');
    const eyeIcon = document.getElementById('eyeIcon');
    if (!pwdInput || !eyeIcon) return;

    if (pwdInput.type === 'password') {
        pwdInput.type = 'text';
        eyeIcon.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        pwdInput.type = 'password';
        eyeIcon.classList.replace('fa-eye-slash', 'fa-eye');
    }
}

/* 2. 原生 Bootstrap 主题切换 */
function toggleTheme() {
    const html = document.documentElement;
    const currentTheme = html.getAttribute('data-bs-theme') || 'light';
    const newTheme = (currentTheme === 'light') ? 'dark' : 'light';
    
    document.cookie = "theme=" + newTheme + ";path=/;max-age=" + (30*24*60*60) + ";SameSite=Lax";
    window.location.reload();
}

function updateThemeToggleIcons(theme) {
    const buttons = document.querySelectorAll('.theme-toggle-btn');
    buttons.forEach(btn => {
        const icon = btn.querySelector('i');
        if (icon) {
            if (theme === 'dark') {
                icon.className = 'fas fa-sun';
            } else {
                icon.className = 'fas fa-moon';
            }
        }
    });
}

// 自动监听并在页面加载时动态配置 Chart.js 图表色调与切换按钮图标
if (typeof window !== 'undefined') {
    document.addEventListener("DOMContentLoaded", function() {
        const currentTheme = document.documentElement.getAttribute('data-bs-theme') || 'light';
        updateThemeToggleIcons(currentTheme);
        updateChartsTheme(currentTheme);
    });
}

function updateChartsTheme(theme) {
    const isDark = theme === 'dark';
    const textColor = isDark ? '#adb5bd' : '#495057';
    const gridColor = isDark ? 'rgba(255,255,255,0.1)' : 'rgba(0,0,0,0.05)';

    const canvas = document.getElementById('salesChart');
    if (canvas && typeof Chart !== 'undefined') {
        const chart = Chart.getChart(canvas);
        if (chart) {
            if (chart.options.scales && chart.options.scales.y) {
                chart.options.scales.y.grid.color = gridColor;
                chart.options.scales.y.ticks.color = textColor;
            }
            if (chart.options.scales && chart.options.scales.x) {
                chart.options.scales.x.ticks.color = textColor;
            }
            chart.update();
        }
    }
}

/* 3. 系统语言切换 */
function changeLang(lang, event) {
    if (event) {
        event.preventDefault();
    }
    document.cookie = "lang=" + lang + ";path=/;max-age=" + (30*24*60*60) + ";SameSite=Lax";
    window.location.reload();
}

/* 4. 复制演示版重置链接 */
function copyResetLink() {
    const copyText = document.getElementById("linkInput");
    const copyBtn = document.getElementById("copyBtn");
    if (!copyText || !copyBtn) return;

    copyText.select();
    copyText.setSelectionRange(0, 99999); 
    
    navigator.clipboard.writeText(copyText.value).then(() => {
        const originalIcon = copyBtn.innerHTML;
        copyBtn.innerHTML = '<i class="fas fa-check text-success"></i>';
        setTimeout(() => {
            copyBtn.innerHTML = originalIcon;
        }, 2000);
    }).catch(err => {
        console.error("Failed to copy text: ", err);
    });
}

/* 5. 数据表格导出工具（Excel, CSV, PDF） */
function printCurrentPage() {
    window.print();
}

function exportTableToExcel(tableId, filename = 'Export_Data.xlsx', sheetName = 'Data Sheet') {
    if (typeof XLSX === 'undefined') {
        alert(getJsTranslation('excel_err'));
        return;
    }
    const table = document.getElementById(tableId);
    if (!table) return;

    const clonedTable = table.cloneNode(true);
    clonedTable.querySelectorAll('.no-print, .d-print-none, .no-export').forEach(el => el.remove());
    clonedTable.querySelectorAll('tr').forEach(tr => {
        tr.querySelectorAll('.no-print, .d-print-none, .no-export').forEach(td => td.remove());
    });

    const wb = XLSX.utils.table_to_book(clonedTable, { sheet: sheetName });
    XLSX.writeFile(wb, filename);
}

function exportTableToCSV(tableId, filename = 'Export_Data.csv') {
    const table = document.getElementById(tableId);
    if (!table) return;

    const rows = Array.from(table.querySelectorAll('tr'));
    const csvContent = rows.map(row => {
        return Array.from(row.querySelectorAll('th, td'))
            .filter(cell => !cell.classList.contains('no-print') && !cell.classList.contains('d-print-none') && !cell.classList.contains('no-export'))
            .map(cell => {
                const text = (cell.textContent || '').replace(/\s+/g, ' ').trim();
                return '"' + text.replace(/"/g, '""') + '"';
            })
            .join(',');
    }).filter(row => row.length > 0).join('\n');

    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    link.click();
    URL.revokeObjectURL(url);
}

function exportTableToPDF(tableId, reportTitle, filename = 'Report.pdf') {
    if (typeof jspdf === 'undefined') {
        alert(getJsTranslation('pdf_err'));
        return;
    }
    
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF('p', 'pt', 'a4');
    
    doc.text(reportTitle, 40, 40);
    
    const table = document.getElementById(tableId);
    if (!table) return;
    
    const clonedTable = table.cloneNode(true);
    clonedTable.querySelectorAll('.no-print, .d-print-none, .no-export').forEach(el => el.remove());
    clonedTable.querySelectorAll('tr').forEach(tr => {
        tr.querySelectorAll('.no-print, .d-print-none, .no-export').forEach(td => td.remove());
    });
    
    doc.autoTable({
        html: clonedTable,
        startY: 60,
        styles: { fontSize: 8, font: "Helvetica" },
        theme: 'striped'
    });
    
    doc.save(filename);
}

/* =========================================================================
   6. 物理热敏打印机调用接口 (物理底层解耦重构 - 100% 屏蔽乱码干扰)
   ========================================================================= */
function sendToThermalPrinter(htmlContent, docTitle = 'Receipt') {
    fallbackIframePrint(htmlContent, docTitle);
}

function fallbackIframePrint(htmlContent, docTitle) {
    let iframe = document.getElementById("pos-print-iframe-sandbox");
    if (iframe) iframe.remove();

    iframe = document.createElement("iframe");
    iframe.id = "pos-print-iframe-sandbox";
    // 💡 改进一：使用绝对离屏定位和 74mm 物理感知宽度，让浏览器强制按 80 窄卷排版并遵守 @page margin 0
    iframe.style.position = "absolute";
    iframe.style.left = "-9999px";
    iframe.style.top = "-9999px";
    iframe.style.width = "74mm";
    iframe.style.height = "1px";
    iframe.style.opacity = "0";
    iframe.style.border = "none";
    document.body.appendChild(iframe);

    const doc = iframe.contentDocument || iframe.contentWindow.document;
    doc.open();
    doc.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title></title> 
            <style>
                @page { 
                    size: 80mm auto; 
                    margin: 0mm !important; 
                }
                @media print {
                    @page { margin: 0 !important; }
                    body { margin: 0 !important; }
                }
                html {
                    height: auto !important;
                    overflow: visible !important;
                }
                body { 
                    margin: 0 !important; 
                    /* padding-bottom 设置 15mm 安全边距，防止物理切刀切断最底部的小票文字 */
                    padding: 2mm 3mm 15mm 3mm !important; 
                    background-color: #fff !important; 
                    width: 74mm !important;
                    box-sizing: border-box !important;
                    -webkit-print-color-adjust: exact !important;
                    print-color-adjust: exact !important;
                    height: auto !important;
                    overflow: visible !important;
                }
                * { 
                    font-size: 11px !important; 
                    line-height: 1.4 !important;
                    font-family: 'Courier New', Courier, monospace !important; 
                    color: #000 !important;
                    height: auto !important;
                    min-height: 0 !important;
                    position: static !important;
                    box-sizing: border-box !important;
                    page-break-inside: avoid !important;
                    break-inside: avoid !important;
                }
                h4 {
                    font-size: 13px !important;
                    font-weight: bold !important;
                    color: #000 !important;
                    text-align: center !important;
                }
                table { 
                    width: 100% !important; 
                    table-layout: fixed !important; 
                    word-wrap: break-word !important; 
                    border-collapse: collapse !important;
                }
                th, td { 
                    padding: 2px 0 !important; 
                    border: none !important; 
                }
            </style>
        </head>
        <body>
            <div>
                ${htmlContent}
            </div>
        </body>
        </html>
    `);
    doc.close();

    // 💡 改进二：动态双重隐藏页面 Title，避免页眉区域出现 “BOH” 标签页标题
    const originalParentTitle = window.parent.document.title || document.title;
    window.parent.document.title = ""; 
    doc.title = "";

    setTimeout(() => {
        iframe.contentWindow.focus();
        iframe.contentWindow.print();
        setTimeout(() => {
            window.parent.document.title = originalParentTitle; // 还原父级 Title
            if (iframe) iframe.remove();
        }, 1000);
    }, 300);
}