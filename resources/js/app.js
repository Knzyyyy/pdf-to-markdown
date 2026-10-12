import './bootstrap';

/**
 * Enhancement frontend untuk PDF ke Markdown.
 *
 * Aturan penting: halaman ini harus tetap FUNGSIONAL tanpa JavaScript.
 * Form tetap terkirim normal ke server; JavaScript hanya menambah
 * drag & drop, tab, tombol salin, dan indikator loading.
 */

/** Format ukuran file dalam bahasa manusia: 1.5 MB */
function formatBytes(bytes) {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(2)} MB`;
}

// ---------- Drag & drop + validasi ringan di sisi klien ----------
// Catatan: ini hanya PENUNJANG. Validasi sesungguhnya ada di backend
// (ConvertPdfRequest), jadiarettes pastian tetap ditolak.
const dropzone = document.getElementById('dropzone');
const fileInput = document.getElementById('file-input');
const fileNameLabel = document.getElementById('file-name');
const form = document.getElementById('upload-form');
const submitBtn = document.getElementById('submit-btn');
const loader = document.getElementById('loader');
const maxSize = dropzone ? Number(dropzone.dataset.maxSize) : 0;

function showSelectedFile(file) {
    if (!fileNameLabel) return;

    if (!file) {
        fileNameLabel.textContent = '';
        fileNameLabel.classList.remove('is-invalid');
        return;
    }

    const wrongType = file.type !== 'application/pdf' && !file.name.toLowerCase().endsWith('.pdf');
    const tooBig = maxSize > 0 && file.size > maxSize;

    if (wrongType || tooBig) {
        fileNameLabel.textContent = tooBig
            ? `${file.name} (${formatBytes(file.size)}) - melebihi batas 15 MB`
            : `${file.name} - bukan file PDF`;
        fileNameLabel.classList.add('is-invalid');
    } else {
        fileNameLabel.textContent = `${file.name} (${formatBytes(file.size)})`;
        fileNameLabel.classList.remove('is-invalid');
    }
}

if (dropzone && fileInput) {
    ['dragenter', 'dragover'].forEach((eventName) => {
        dropzone.addEventListener(eventName, (event) => {
            event.preventDefault();
            dropzone.classList.add('is-dragover');
        });
    });

    ['dragleave', 'drop'].forEach((eventName) => {
        dropzone.addEventListener(eventName, (event) => {
            event.preventDefault();
            dropzone.classList.remove('is-dragover');
        });
    });

    dropzone.addEventListener('drop', (event) => {
        const files = event.dataTransfer?.files;
        if (!files || files.length === 0) return;

        // DataTransfer bisa diisi ulang, jadi samakan dengan input file.
        fileInput.files = files;
        showSelectedFile(files[0]);
    });

    fileInput.addEventListener('change', () => {
        showSelectedFile(fileInput.files[0]);
    });
}

// ---------- Loading indicator ----------
if (form && submitBtn && loader) {
    form.addEventListener('submit', () => {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Mengonversi...';
        loader.hidden = false;
    });
}

// ---------- Tab Preview / Raw ----------
// Tab punya dua peran: klik untuk berpindah, dan keyboard (panah kiri/kanan)
// supaya bisa dipakai tanpa mouse.
const tabs = Array.from(document.querySelectorAll('[role="tab"]'));

function selectTab(tab) {
    tabs.forEach((other) => {
        const isSelected = other === tab;
        other.setAttribute('aria-selected', String(isSelected));

        const panel = document.getElementById(other.getAttribute('aria-controls'));

        if (panel) {
            panel.hidden = !isSelected;
        }
    });
}

tabs.forEach((tab, index) => {
    tab.addEventListener('click', () => selectTab(tab));

    tab.addEventListener('keydown', (event) => {
        if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') return;

        event.preventDefault();

        const offset = event.key === 'ArrowRight' ? 1 : -1;
        const next = tabs[(index + offset + tabs.length) % tabs.length];
        next.focus();
        selectTab(next);
    });
});

// ---------- Tombol salin ----------
const copyBtn = document.getElementById('copy-btn');
const rawPanel = document.getElementById('panel-raw');

if (copyBtn && rawPanel) {
    copyBtn.addEventListener('click', async () => {
        const original = copyBtn.textContent;
        const markdown = rawPanel.textContent ?? '';

        try {
            await navigator.clipboard.writeText(markdown);
            copyBtn.textContent = 'Tersalin';
        } catch {
            // Clipboard API butuh HTTPS atau izin browser.
            // Cadangan: tampilkan tab Raw supaya pengguna bisa Ctrl+C manual.
            selectTab(tabs.find((tab) => tab.getAttribute('aria-controls') === 'panel-raw'));
            copyBtn.textContent = 'Buka tab Raw, lalu Ctrl+C';
        }

        setTimeout(() => {
            copyBtn.textContent = original;
        }, 2500);
    });
}