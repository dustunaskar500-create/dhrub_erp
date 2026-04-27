/**
 * CMS JavaScript - Dhrub Foundation
 */

const API_BASE = '../api';
let token = localStorage.getItem('erp_token') || sessionStorage.getItem('token') || '';
let currentSection = 'blog';
let programs = [];
let blogPosts = [];
let stories = [];
let mediaItems = [];
let deleteTarget = null;
let tinymceEditor = null;

// Check authentication
if (!token) {
    window.location.href = '../';
}

// Initialize
document.addEventListener('DOMContentLoaded', () => {
    initTinyMCE();
    loadPrograms();
    loadBlogPosts();
    loadStories();
    loadMedia();
    setupNavigation();
    setupDragDrop();
});

// TinyMCE Initialization
function initTinyMCE() {
    tinymce.init({
        selector: '.tinymce-editor',
        height: 400,
        menubar: false,
        plugins: [
            'advlist', 'autolink', 'lists', 'link', 'image', 'charmap', 'preview',
            'anchor', 'searchreplace', 'visualblocks', 'code', 'fullscreen',
            'insertdatetime', 'media', 'table', 'help', 'wordcount'
        ],
        toolbar: 'undo redo | blocks | bold italic forecolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | removeformat | image link | code help',
        content_style: 'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; font-size: 14px; line-height: 1.6; }',
        images_upload_handler: uploadTinyMCEImage,
        automatic_uploads: true,
        file_picker_types: 'image',
        file_picker_callback: openTinyMCEImagePicker,
        setup: (editor) => {
            tinymceEditor = editor;
        }
    });
}

// TinyMCE Image Upload Handler
async function uploadTinyMCEImage(blobInfo, progress) {
    const formData = new FormData();
    formData.append('file', blobInfo.blob(), blobInfo.filename());
    
    try {
        const res = await fetch(`${API_BASE}/cms/upload`, {
            method: 'POST',
            headers: { 'Authorization': `Bearer ${token}` },
            body: formData
        });
        
        const data = await res.json();
        if (data.url) {
            return data.url;
        }
        throw new Error(data.detail || 'Upload failed');
    } catch (error) {
        throw new Error('Image upload failed: ' + error.message);
    }
}

// TinyMCE Image Picker
function openTinyMCEImagePicker(callback, value, meta) {
    if (meta.filetype === 'image') {
        openMediaPicker((imageUrl) => {
            callback(imageUrl, { alt: 'Image' });
        });
    }
}

// API Helper
async function api(endpoint, method = 'GET', data = null, isFormData = false) {
    const options = {
        method,
        headers: {
            'Authorization': `Bearer ${token}`
        }
    };
    
    if (data && !isFormData) {
        options.headers['Content-Type'] = 'application/json';
        options.body = JSON.stringify(data);
    } else if (data && isFormData) {
        options.body = data;
    }
    
    try {
        const res = await fetch(`${API_BASE}${endpoint}`, options);
        if (res.status === 401) {
            window.location.href = '/';
            return null;
        }
        return await res.json();
    } catch (error) {
        console.error('API Error:', error);
        return null;
    }
}

// Alert helper
function showAlert(message, type = 'success') {
    const container = document.getElementById('alert-container');
    container.innerHTML = `
        <div class="alert alert-${type}">
            <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
            <span>${message}</span>
        </div>
    `;
    setTimeout(() => container.innerHTML = '', 4000);
}

// Navigation
function setupNavigation() {
    document.querySelectorAll('.nav-item[data-section]').forEach(item => {
        item.addEventListener('click', (e) => {
            e.preventDefault();
            const section = item.dataset.section;
            switchSection(section);
        });
    });
}

function switchSection(section) {
    currentSection = section;
    
    // Update nav items
    document.querySelectorAll('.nav-item[data-section]').forEach(item => {
        item.classList.toggle('active', item.dataset.section === section);
    });
    
    // Update sections
    document.querySelectorAll('.content-section').forEach(s => {
        s.classList.add('hidden');
    });
    document.getElementById(`${section}-section`).classList.remove('hidden');
    
    // Update header
    const titles = { blog: 'Blog Posts', stories: 'Success Stories', media: 'Media Library' };
    const btnTexts = { blog: 'New Post', stories: 'New Story', media: '' };
    
    document.getElementById('page-title').textContent = titles[section];
    const newBtn = document.getElementById('new-item-btn');
    if (section === 'media') {
        newBtn.style.display = 'none';
    } else {
        newBtn.style.display = 'inline-flex';
        newBtn.querySelector('span').textContent = btnTexts[section];
    }
}

// Mobile sidebar toggle
function toggleSidebar() {
    document.querySelector('.cms-sidebar').classList.toggle('open');
}

// Load data
async function loadPrograms() {
    const data = await api('/programs');
    if (data && data.items) {
        programs = data.items;
        updateProgramSelects();
    }
}

function updateProgramSelects() {
    const selects = ['story-program', 'stories-filter-program'];
    selects.forEach(id => {
        const select = document.getElementById(id);
        if (select) {
            const firstOption = select.querySelector('option');
            select.innerHTML = firstOption.outerHTML + programs.map(p => 
                `<option value="${p.id}">${p.program_name}</option>`
            ).join('');
        }
    });
}

async function loadBlogPosts() {
    const data = await api('/blog');
    blogPosts = data?.items || [];
    renderBlogPosts();
}

async function loadStories() {
    const data = await api('/stories');
    stories = data?.items || [];
    renderStories();
}

async function loadMedia() {
    const data = await api('/cms/media');
    mediaItems = data?.items || [];
    renderMedia();
}

// Render functions
function renderBlogPosts(filter = null) {
    const grid = document.getElementById('blog-grid');
    let items = [...blogPosts];
    
    // Apply filters
    const searchTerm = document.getElementById('blog-search')?.value.toLowerCase();
    const categoryFilter = document.getElementById('blog-filter-category')?.value;
    const statusFilter = document.getElementById('blog-filter-status')?.value;
    
    if (searchTerm) {
        items = items.filter(p => p.title?.toLowerCase().includes(searchTerm));
    }
    if (categoryFilter) {
        items = items.filter(p => p.category === categoryFilter);
    }
    if (statusFilter !== '') {
        items = items.filter(p => String(p.is_published) === statusFilter);
    }
    
    if (items.length === 0) {
        grid.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-newspaper"></i>
                <p>No blog posts found</p>
            </div>
        `;
        return;
    }
    
    grid.innerHTML = items.map(post => `
        <div class="item-card" data-id="${post.id}">
            <div class="item-image">
                ${post.featured_image 
                    ? `<img src="${post.featured_image}" alt="${post.title}">`
                    : '<i class="fas fa-newspaper"></i>'
                }
            </div>
            <div class="item-body">
                <div class="item-meta">
                    <span class="item-category">${post.category || 'News'}</span>
                    <span class="item-status ${post.is_published ? 'published' : 'draft'}">
                        ${post.is_published ? 'Published' : 'Draft'}
                    </span>
                </div>
                <h3 class="item-title">${post.title}</h3>
                <p class="item-excerpt">${post.excerpt || stripHtml(post.content || '').substring(0, 100) + '...'}</p>
                <div class="item-footer">
                    <span class="item-date">${formatDate(post.created_at)}</span>
                    <div class="item-actions">
                        <button onclick="editItem('blog', ${post.id})" title="Edit">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="delete" onclick="deleteItem('blog', ${post.id})" title="Delete">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `).join('');
}

function renderStories(filter = null) {
    const grid = document.getElementById('stories-grid');
    let items = [...stories];
    
    // Apply filters
    const searchTerm = document.getElementById('stories-search')?.value.toLowerCase();
    const programFilter = document.getElementById('stories-filter-program')?.value;
    const statusFilter = document.getElementById('stories-filter-status')?.value;
    
    if (searchTerm) {
        items = items.filter(s => s.title?.toLowerCase().includes(searchTerm) || 
                                   s.beneficiary_name?.toLowerCase().includes(searchTerm));
    }
    if (programFilter) {
        items = items.filter(s => String(s.program_id) === programFilter);
    }
    if (statusFilter !== '') {
        items = items.filter(s => String(s.is_published) === statusFilter);
    }
    
    if (items.length === 0) {
        grid.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-heart"></i>
                <p>No success stories found</p>
            </div>
        `;
        return;
    }
    
    grid.innerHTML = items.map(story => `
        <div class="item-card" data-id="${story.id}">
            <div class="item-image">
                ${story.featured_image 
                    ? `<img src="${story.featured_image}" alt="${story.title}">`
                    : '<i class="fas fa-heart"></i>'
                }
            </div>
            <div class="item-body">
                <div class="item-meta">
                    <span class="item-category">${story.program_name || 'General'}</span>
                    <span class="item-status ${story.is_published ? 'published' : 'draft'}">
                        ${story.is_published ? 'Published' : 'Draft'}
                    </span>
                </div>
                <h3 class="item-title">${story.title}</h3>
                <p class="item-excerpt">${story.beneficiary_name ? `${story.beneficiary_name}${story.age ? `, ${story.age} years` : ''}` : stripHtml(story.story || '').substring(0, 80) + '...'}</p>
                <div class="item-footer">
                    <span class="item-date">${formatDate(story.created_at)}</span>
                    <div class="item-actions">
                        <button onclick="editItem('story', ${story.id})" title="Edit">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="delete" onclick="deleteItem('story', ${story.id})" title="Delete">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `).join('');
}

function renderMedia() {
    const grid = document.getElementById('media-grid');
    
    if (mediaItems.length === 0) {
        grid.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-images"></i>
                <p>No media files yet. Upload some images!</p>
            </div>
        `;
        return;
    }
    
    grid.innerHTML = mediaItems.map(item => `
        <div class="media-item" data-id="${item.id}" data-url="${item.url}">
            <img src="${item.url}" alt="${item.filename}">
            <div class="media-item-overlay">
                <span class="media-item-name">${item.filename}</span>
            </div>
        </div>
    `).join('');
}

function filterItems(type) {
    if (type === 'blog') {
        renderBlogPosts();
    } else {
        renderStories();
    }
}

function filterMedia() {
    const searchTerm = document.getElementById('media-search')?.value.toLowerCase();
    const grid = document.getElementById('media-grid');
    
    let items = [...mediaItems];
    if (searchTerm) {
        items = items.filter(m => m.filename?.toLowerCase().includes(searchTerm));
    }
    
    if (items.length === 0) {
        grid.innerHTML = '<div class="empty-state"><i class="fas fa-search"></i><p>No media found</p></div>';
        return;
    }
    
    grid.innerHTML = items.map(item => `
        <div class="media-item" data-id="${item.id}" data-url="${item.url}">
            <img src="${item.url}" alt="${item.filename}">
            <div class="media-item-overlay">
                <span class="media-item-name">${item.filename}</span>
            </div>
        </div>
    `).join('');
}

// Modal functions
function openModal(type = null) {
    const itemType = type || (currentSection === 'stories' ? 'story' : 'blog');
    document.getElementById('item-type').value = itemType;
    document.getElementById('item-id').value = '';
    document.getElementById('item-title').value = '';
    document.getElementById('item-status').value = '0';
    document.getElementById('item-image').value = '';
    
    // Reset image preview
    document.getElementById('image-preview').innerHTML = `
        <i class="fas fa-image"></i>
        <span>No image selected</span>
    `;
    
    // Toggle fields
    document.getElementById('blog-fields').classList.toggle('hidden', itemType !== 'blog');
    document.getElementById('story-fields').classList.toggle('hidden', itemType !== 'story');
    
    // Reset type-specific fields
    if (itemType === 'blog') {
        document.getElementById('blog-category').value = 'News';
        document.getElementById('blog-excerpt').value = '';
    } else {
        document.getElementById('story-beneficiary').value = '';
        document.getElementById('story-age').value = '';
        document.getElementById('story-program').value = '';
    }
    
    // Reset TinyMCE
    if (tinymceEditor) {
        tinymceEditor.setContent('');
    }
    
    document.getElementById('modal-title').textContent = itemType === 'blog' ? 'New Blog Post' : 'New Success Story';
    document.getElementById('item-modal').classList.add('active');
}

function closeModal() {
    document.getElementById('item-modal').classList.remove('active');
}

async function editItem(type, id) {
    const endpoint = type === 'blog' ? '/blog' : '/stories';
    const item = await api(`${endpoint}/${id}`);
    
    if (!item) {
        showAlert('Failed to load item', 'error');
        return;
    }
    
    document.getElementById('item-type').value = type;
    document.getElementById('item-id').value = item.id;
    document.getElementById('item-title').value = item.title || '';
    document.getElementById('item-status').value = item.is_published ? '1' : '0';
    document.getElementById('item-image').value = item.featured_image || '';
    
    // Update image preview
    const preview = document.getElementById('image-preview');
    if (item.featured_image) {
        preview.innerHTML = `<img src="${item.featured_image}" alt="Featured image">`;
    } else {
        preview.innerHTML = `<i class="fas fa-image"></i><span>No image selected</span>`;
    }
    
    // Toggle fields
    document.getElementById('blog-fields').classList.toggle('hidden', type !== 'blog');
    document.getElementById('story-fields').classList.toggle('hidden', type !== 'story');
    
    if (type === 'blog') {
        document.getElementById('blog-category').value = item.category || 'News';
        document.getElementById('blog-excerpt').value = item.excerpt || '';
        if (tinymceEditor) {
            tinymceEditor.setContent(item.content || '');
        }
    } else {
        document.getElementById('story-beneficiary').value = item.beneficiary_name || '';
        document.getElementById('story-age').value = item.age || '';
        document.getElementById('story-program').value = item.program_id || '';
        if (tinymceEditor) {
            tinymceEditor.setContent(item.story || '');
        }
    }
    
    document.getElementById('modal-title').textContent = type === 'blog' ? 'Edit Blog Post' : 'Edit Success Story';
    document.getElementById('item-modal').classList.add('active');
}

async function saveItem(e) {
    e.preventDefault();
    
    const type = document.getElementById('item-type').value;
    const id = document.getElementById('item-id').value;
    const endpoint = type === 'blog' ? '/blog' : '/stories';
    
    // Get content from TinyMCE
    const content = tinymceEditor ? tinymceEditor.getContent() : '';
    
    let data;
    if (type === 'blog') {
        data = {
            title: document.getElementById('item-title').value,
            category: document.getElementById('blog-category').value,
            excerpt: document.getElementById('blog-excerpt').value,
            content: content,
            featured_image: document.getElementById('item-image').value || null,
            is_published: parseInt(document.getElementById('item-status').value)
        };
    } else {
        data = {
            title: document.getElementById('item-title').value,
            beneficiary_name: document.getElementById('story-beneficiary').value || null,
            age: document.getElementById('story-age').value ? parseInt(document.getElementById('story-age').value) : null,
            program_id: document.getElementById('story-program').value || null,
            story: content,
            featured_image: document.getElementById('item-image').value || null,
            is_published: parseInt(document.getElementById('item-status').value)
        };
    }
    
    const method = id ? 'PUT' : 'POST';
    const url = id ? `${endpoint}/${id}` : endpoint;
    
    const result = await api(url, method, data);
    
    if (result && !result.detail) {
        showAlert(id ? 'Item updated successfully!' : 'Item created successfully!');
        closeModal();
        if (type === 'blog') {
            loadBlogPosts();
        } else {
            loadStories();
        }
    } else {
        showAlert(result?.detail || 'Failed to save item', 'error');
    }
}

// Delete functions
function deleteItem(type, id) {
    deleteTarget = { type, id };
    document.getElementById('delete-modal').classList.add('active');
}

function closeDeleteModal() {
    document.getElementById('delete-modal').classList.remove('active');
    deleteTarget = null;
}

async function confirmDelete() {
    if (!deleteTarget) return;
    
    const { type, id } = deleteTarget;
    const endpoint = type === 'blog' ? '/blog' : '/stories';
    
    const result = await api(`${endpoint}/${id}`, 'DELETE');
    
    if (result && !result.detail) {
        showAlert('Item deleted successfully!');
        if (type === 'blog') {
            loadBlogPosts();
        } else {
            loadStories();
        }
    } else {
        showAlert(result?.detail || 'Failed to delete item', 'error');
    }
    
    closeDeleteModal();
}

// Media Picker
let mediaPickerCallback = null;

function openMediaPicker(callback = null) {
    mediaPickerCallback = callback;
    
    // Render media in picker
    const grid = document.getElementById('media-picker-grid');
    if (mediaItems.length === 0) {
        grid.innerHTML = '<div class="empty-state"><i class="fas fa-images"></i><p>No media available. Upload some images first!</p></div>';
    } else {
        grid.innerHTML = mediaItems.map(item => `
            <div class="media-item" data-url="${item.url}" onclick="selectMediaItem(this, '${item.url}')">
                <img src="${item.url}" alt="${item.filename}">
            </div>
        `).join('');
    }
    
    document.getElementById('media-picker-modal').classList.add('active');
}

function closeMediaPicker() {
    document.getElementById('media-picker-modal').classList.remove('active');
    mediaPickerCallback = null;
}

function selectMediaItem(element, url) {
    // Remove selection from others
    document.querySelectorAll('#media-picker-grid .media-item').forEach(item => {
        item.classList.remove('selected');
    });
    
    element.classList.add('selected');
    
    // Set the image
    document.getElementById('item-image').value = url;
    document.getElementById('image-preview').innerHTML = `<img src="${url}" alt="Selected image">`;
    
    if (mediaPickerCallback) {
        mediaPickerCallback(url);
    }
    
    closeMediaPicker();
}

// File Upload
function setupDragDrop() {
    const uploadZone = document.getElementById('upload-zone');
    if (!uploadZone) return;
    
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(event => {
        uploadZone.addEventListener(event, (e) => {
            e.preventDefault();
            e.stopPropagation();
        });
    });
    
    ['dragenter', 'dragover'].forEach(event => {
        uploadZone.addEventListener(event, () => uploadZone.classList.add('dragover'));
    });
    
    ['dragleave', 'drop'].forEach(event => {
        uploadZone.addEventListener(event, () => uploadZone.classList.remove('dragover'));
    });
    
    uploadZone.addEventListener('drop', (e) => {
        const files = e.dataTransfer.files;
        if (files.length) {
            uploadFiles(files);
        }
    });
}

function handleFileUpload(e) {
    const files = e.target.files;
    if (files.length) {
        uploadFiles(files);
    }
}

async function uploadFiles(files) {
    for (const file of files) {
        if (!file.type.startsWith('image/')) {
            showAlert('Only image files are allowed', 'error');
            continue;
        }
        
        const formData = new FormData();
        formData.append('file', file);
        
        const result = await api('/cms/upload', 'POST', formData, true);
        
        if (result && result.url) {
            showAlert(`${file.name} uploaded successfully!`);
            loadMedia();
        } else {
            showAlert(`Failed to upload ${file.name}`, 'error');
        }
    }
}

async function handleDirectUpload(e) {
    const file = e.target.files[0];
    if (!file) return;
    
    if (!file.type.startsWith('image/')) {
        showAlert('Only image files are allowed', 'error');
        return;
    }
    
    const formData = new FormData();
    formData.append('file', file);
    
    const result = await api('/cms/upload', 'POST', formData, true);
    
    if (result && result.url) {
        document.getElementById('item-image').value = result.url;
        document.getElementById('image-preview').innerHTML = `<img src="${result.url}" alt="Uploaded image">`;
        showAlert('Image uploaded successfully!');
        loadMedia();
    } else {
        showAlert('Failed to upload image', 'error');
    }
}

async function handlePickerUpload(e) {
    const file = e.target.files[0];
    if (!file) return;
    
    if (!file.type.startsWith('image/')) {
        showAlert('Only image files are allowed', 'error');
        return;
    }
    
    const formData = new FormData();
    formData.append('file', file);
    
    const result = await api('/cms/upload', 'POST', formData, true);
    
    if (result && result.url) {
        showAlert('Image uploaded successfully!');
        await loadMedia();
        
        // Update picker grid
        const grid = document.getElementById('media-picker-grid');
        grid.innerHTML = mediaItems.map(item => `
            <div class="media-item${item.url === result.url ? ' selected' : ''}" data-url="${item.url}" onclick="selectMediaItem(this, '${item.url}')">
                <img src="${item.url}" alt="${item.filename}">
            </div>
        `).join('');
        
        // Auto-select the newly uploaded image
        document.getElementById('item-image').value = result.url;
        document.getElementById('image-preview').innerHTML = `<img src="${result.url}" alt="Uploaded image">`;
    } else {
        showAlert('Failed to upload image', 'error');
    }
}

// Utility functions
function stripHtml(html) {
    const tmp = document.createElement('div');
    tmp.innerHTML = html;
    return tmp.textContent || tmp.innerText || '';
}

function formatDate(dateString) {
    if (!dateString) return '';
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}
