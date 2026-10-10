/**
 * Merrylay Enterprises Enterprises JavaScript Application
 *
 * Core JavaScript functionality for the Merrylay Enterprises Enterprises inventory and sales management system.
 */

// ===== GLOBAL CONFIGURATION =====
const MerrylayApp = {
    config: {
        apiBaseUrl: '/merrylay_system/api',
        csrfToken: null,
        userId: null,
        debug: false
    },
    
    init() {
        this.setupEventListeners();
        this.initializeComponents();
        this.loadUserPreferences();
        this.setupCSRFProtection();
        
        if (this.config.debug) {
            console.log('Merrylay Enterprises App initialized');
        }
    },
    
    setupCSRFProtection() {
        const token = document.querySelector('meta[name="csrf-token"]');
        if (token) {
            this.config.csrfToken = token.getAttribute('content');
        }
    },
    
    setupEventListeners() {
        // Sidebar toggle
        const sidebarToggle = document.getElementById('sidebarToggle');
        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', this.toggleSidebar.bind(this));
        }
        
        // Mobile menu
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        if (mobileMenuToggle) {
            mobileMenuToggle.addEventListener('click', this.toggleMobileMenu.bind(this));
        }
        
        // Dark mode toggle
        const darkModeToggle = document.getElementById('darkModeToggle');
        if (darkModeToggle) {
            darkModeToggle.addEventListener('click', this.toggleDarkMode.bind(this));
        }
        
        // Form submissions
        const forms = document.querySelectorAll('form[data-ajax]');
        forms.forEach(form => {
            form.addEventListener('submit', this.handleAjaxForm.bind(this));
        });
        
        // Notification close buttons
        const notificationCloses = document.querySelectorAll('.notification-close');
        notificationCloses.forEach(button => {
            button.addEventListener('click', this.closeNotification.bind(this));
        });
        
        // Table sorting
        const sortableHeaders = document.querySelectorAll('.table th.sortable');
        sortableHeaders.forEach(header => {
            header.addEventListener('click', this.handleTableSort.bind(this));
        });
        
        // Modal triggers
        const modalTriggers = document.querySelectorAll('[data-modal-trigger]');
        modalTriggers.forEach(trigger => {
            trigger.addEventListener('click', this.openModal.bind(this));
        });
        
        // Modal close buttons
        const modalCloses = document.querySelectorAll('[data-modal-close]');
        modalCloses.forEach(close => {
            close.addEventListener('click', this.closeModal.bind(this));
        });
        
        // Click outside modal to close
        document.addEventListener('click', this.handleModalOutsideClick.bind(this));
        
        // Keyboard shortcuts
        document.addEventListener('keydown', this.handleKeyboardShortcuts.bind(this));
    },
    
    initializeComponents() {
        this.initializeTooltips();
        this.initializeDropdowns();
        this.initializeDatePickers();
        this.initializeCharts();
        this.initializeDataTables();
    },
    
    // ===== SIDEBAR FUNCTIONALITY =====
    toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        
        if (sidebar && mainContent) {
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
            
            // Save preference
            localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
        }
    },
    
    toggleMobileMenu() {
        const sidebar = document.getElementById('sidebar');
        if (sidebar) {
            sidebar.classList.toggle('mobile-open');
        }
    },
    
    // ===== DARK MODE =====
    toggleDarkMode() {
        document.documentElement.classList.toggle('dark-mode');
        const isDarkMode = document.documentElement.classList.contains('dark-mode');
        
        // Save preference
        localStorage.setItem('darkMode', isDarkMode);
        
        // Update icon
        const darkModeToggle = document.getElementById('darkModeToggle');
        if (darkModeToggle) {
            const icon = darkModeToggle.querySelector('i');
            if (icon) {
                icon.className = isDarkMode ? 'fas fa-sun' : 'fas fa-moon';
            }
        }
        
        // Update charts for dark mode
        this.updateChartsTheme(isDarkMode);
    },
    
    loadUserPreferences() {
        // Load sidebar state
        const sidebarCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        
        if (sidebarCollapsed && sidebar && mainContent) {
            sidebar.classList.add('collapsed');
            mainContent.classList.add('expanded');
        }
        
        // Load dark mode preference
        const darkMode = localStorage.getItem('darkMode') === 'true';
        if (darkMode) {
            document.documentElement.classList.add('dark-mode');
            
            // Update icon
            const darkModeToggle = document.getElementById('darkModeToggle');
            if (darkModeToggle) {
                const icon = darkModeToggle.querySelector('i');
                if (icon) {
                    icon.className = 'fas fa-sun';
                }
            }
        }
    },
    
    displaySearchResults(results, type) {
        const resultsContainer = document.getElementById('searchResults');
        if (!resultsContainer) return;
        
        // Use document fragment for batch DOM updates
        const fragment = document.createDocumentFragment();
        
        if (results.length === 0) {
            const noResults = document.createElement('div');
            noResults.className = 'search-no-results';
            noResults.textContent = 'No results found';
            fragment.appendChild(noResults);
        } else {
            results.forEach(result => {
                const resultElement = this.createSearchResultElement(result, type);
                fragment.appendChild(resultElement);
            });
        }
        
        // Single DOM update instead of multiple
        resultsContainer.innerHTML = '';
        resultsContainer.appendChild(fragment);
        resultsContainer.style.display = 'block';
    },
    
    createSearchResultElement(result, type) {
        const div = document.createElement('div');
        div.className = 'search-result-item';
        
        switch (type) {
            case 'products':
                div.innerHTML = `
                    <div class="search-result-icon">
                        <i class="fas fa-box"></i>
                    </div>
                    <div class="search-result-content">
                        <div class="search-result-title">${result.name}</div>
                        <div class="search-result-subtitle">${result.category} • Stock: ${result.quantity}</div>
                    </div>
                    <div class="search-result-price">₦${parseFloat(result.price).toFixed(2)}</div>
                `;
                break;
            case 'sales':
                div.innerHTML = `
                    <div class="search-result-icon">
                        <i class="fas fa-receipt"></i>
                    </div>
                    <div class="search-result-content">
                        <div class="search-result-title">#${result.id}</div>
                        <div class="search-result-subtitle">${result.customer} • ${result.date}</div>
                    </div>
                    <div class="search-result-price">₦${parseFloat(result.total).toFixed(2)}</div>
                `;
                break;
            default:
                div.innerHTML = `
                    <div class="search-result-content">
                        <div class="search-result-title">${result.title || result.name}</div>
                        <div class="search-result-subtitle">${result.description || ''}</div>
                    </div>
                `;
        }
        
        div.addEventListener('click', () => {
            this.handleSearchResultClick(result, type);
        });
        
        return div;
    },
    
    clearSearchResults() {
        const resultsContainer = document.getElementById('searchResults');
        if (resultsContainer) {
            resultsContainer.innerHTML = '';
            resultsContainer.style.display = 'none';
        }
    },
    
    // ===== AJAX FORM HANDLING =====
    async handleAjaxForm(event) {
        event.preventDefault();
        
        const form = event.target;
        const submitButton = form.querySelector('button[type="submit"]');
        const originalButtonText = submitButton.innerHTML;
        
        // Show loading state
        submitButton.disabled = true;
        submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
        
        try {
            const formData = new FormData(form);
            const response = await this.apiRequest(form.action, {
                method: form.method || 'POST',
                body: formData
            });
            
            if (response.success) {
                this.showNotification(response.message || 'Operation completed successfully', 'success');
                
                // Handle redirect if specified
                if (response.redirect) {
                    setTimeout(() => {
                        window.location.href = response.redirect;
                    }, 1500);
                } else if (form.dataset.resetOnSuccess) {
                    form.reset();
                }
            } else {
                this.showNotification(response.message || 'Operation failed', 'error');
            }
        } catch (error) {
            console.error('Form submission failed:', error);
            this.showNotification('An error occurred. Please try again.', 'error');
        } finally {
            // Restore button state
            submitButton.disabled = false;
            submitButton.innerHTML = originalButtonText;
        }
    },
    
    // ===== MODAL FUNCTIONALITY =====
    openModal(event) {
        const trigger = event.currentTarget;
        const modalId = trigger.dataset.modalTrigger;
        const modal = document.getElementById(modalId);
        
        if (modal) {
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
            
            // Focus first input
            const firstInput = modal.querySelector('input, select, textarea');
            if (firstInput) {
                setTimeout(() => firstInput.focus(), 100);
            }
        }
    },
    
    closeModal(event) {
        const modal = event.target.closest('.modal');
        if (modal) {
            modal.classList.remove('active');
            document.body.style.overflow = '';
        }
    },
    
    handleModalOutsideClick(event) {
        if (event.target.classList.contains('modal')) {
            this.closeModal(event);
        }
    },
    
    // ===== TABLE SORTING =====
    handleTableSort(event) {
        const header = event.currentTarget;
        const table = header.closest('table');
        const tbody = table.querySelector('tbody');
        const rows = Array.from(tbody.querySelectorAll('tr'));
        const column = header.dataset.column;
        const currentDirection = header.dataset.sortDirection || 'asc';
        const newDirection = currentDirection === 'asc' ? 'desc' : 'asc';
        
        // Update header state
        header.dataset.sortDirection = newDirection;
        
        // Remove existing sort classes
        table.querySelectorAll('th.sortable').forEach(th => {
            th.classList.remove('sort-asc', 'sort-desc');
        });
        
        header.classList.add(`sort-${newDirection}`);
        
        // Sort rows
        rows.sort((a, b) => {
            let aValue = a.cells[column].textContent.trim();
            let bValue = b.cells[column].textContent.trim();
            
            // Handle numeric values
            if (!isNaN(aValue) && !isNaN(bValue)) {
                aValue = parseFloat(aValue);
                bValue = parseFloat(bValue);
            }
            
            if (newDirection === 'asc') {
                return aValue > bValue ? 1 : -1;
            } else {
                return aValue < bValue ? 1 : -1;
            }
        });
        
        // Reorder rows
        rows.forEach(row => tbody.appendChild(row));
    },
    
    // ===== NOTIFICATIONS =====
    showNotification(message, type = 'info', duration = 5000) {
        const notification = document.createElement('div');
        notification.className = `notification notification-${type}`;
        
        // Use DOM methods instead of innerHTML for better performance
        const content = document.createElement('div');
        content.className = 'notification-content';
        
        const icon = document.createElement('i');
        icon.className = `fas ${this.getNotificationIcon(type)}`;
        content.appendChild(icon);
        
        const span = document.createElement('span');
        span.textContent = message;
        content.appendChild(span);
        
        notification.appendChild(content);
        
        const closeBtn = document.createElement('button');
        closeBtn.className = 'notification-close';
        const closeIcon = document.createElement('i');
        closeIcon.className = 'fas fa-times';
        closeBtn.appendChild(closeIcon);
        notification.appendChild(closeBtn);
        
        const container = document.getElementById('notifications') || document.body;
        container.appendChild(notification);
        
        // Animate in
        setTimeout(() => notification.classList.add('show'), 10);
        
        // Auto remove
        if (duration > 0) {
            setTimeout(() => this.closeNotification({ target: closeBtn }), duration);
        }
    },
    
    closeNotification(event) {
        const notification = event.target.closest('.notification');
        if (notification) {
            notification.classList.remove('show');
            setTimeout(() => notification.remove(), 300);
        }
    },
    
    getNotificationIcon(type) {
        const icons = {
            success: 'fa-check-circle',
            error: 'fa-exclamation-circle',
            warning: 'fa-exclamation-triangle',
            info: 'fa-info-circle'
        };
        return icons[type] || icons.info;
    },
    
    // ===== KEYBOARD SHORTCUTS =====
    handleKeyboardShortcuts(event) {
        // Ctrl/Cmd + K for search
        if ((event.ctrlKey || event.metaKey) && event.key === 'k') {
            event.preventDefault();
            const searchInput = document.querySelector('.search-bar input');
            if (searchInput) {
                searchInput.focus();
            }
        }
        
        // Escape to close modals
        if (event.key === 'Escape') {
            const activeModal = document.querySelector('.modal.active');
            if (activeModal) {
                activeModal.classList.remove('active');
                document.body.style.overflow = '';
            }
        }
        
        // Ctrl/Cmd + / for keyboard shortcuts help
        if ((event.ctrlKey || event.metaKey) && event.key === '/') {
            event.preventDefault();
            this.showKeyboardShortcuts();
        }
    },
    
    showKeyboardShortcuts() {
        const shortcuts = [
            { key: 'Ctrl + K', description: 'Focus search' },
            { key: 'Escape', description: 'Close modal' },
            { key: 'Ctrl + /', description: 'Show shortcuts' },
            { key: 'Ctrl + B', description: 'Toggle sidebar' }
        ];
        
        // Use DOM methods instead of innerHTML for better performance
        const container = document.createElement('div');
        container.className = 'keyboard-shortcuts';
        
        const title = document.createElement('h3');
        title.textContent = 'Keyboard Shortcuts';
        container.appendChild(title);
        
        const ul = document.createElement('ul');
        shortcuts.forEach(shortcut => {
            const li = document.createElement('li');
            const kbd = document.createElement('kbd');
            kbd.textContent = shortcut.key;
            li.appendChild(kbd);
            li.appendChild(document.createTextNode(` ${shortcut.description}`));
            ul.appendChild(li);
        });
        container.appendChild(ul);
        
        this.showNotification(container.outerHTML, 'info', 10000);
    },
    
    // ===== API REQUESTS =====
    async apiRequest(endpoint, options = {}) {
        const url = endpoint.startsWith('http') ? endpoint : this.config.apiBaseUrl + endpoint;
        
        const defaultOptions = {
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        };
        
        if (this.config.csrfToken) {
            defaultOptions.headers['X-CSRF-Token'] = this.config.csrfToken;
        }
        
        const finalOptions = {
            ...defaultOptions,
            ...options,
            headers: {
                ...defaultOptions.headers,
                ...options.headers
            }
        };
        
        const response = await fetch(url, finalOptions);
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        return await response.json();
    },
    
    // ===== COMPONENT INITIALIZATION =====
    initializeTooltips() {
        // Simple tooltip implementation
        const tooltipTriggers = document.querySelectorAll('[data-tooltip]');
        tooltipTriggers.forEach(trigger => {
            trigger.addEventListener('mouseenter', this.showTooltip.bind(this));
            trigger.addEventListener('mouseleave', this.hideTooltip.bind(this));
        });
    },
    
    showTooltip(event) {
        const trigger = event.target;
        const text = trigger.dataset.tooltip;
        
        if (!text) return;
        
        const tooltip = document.createElement('div');
        tooltip.className = 'tooltip';
        tooltip.textContent = text;
        
        document.body.appendChild(tooltip);
        
        const rect = trigger.getBoundingClientRect();
        tooltip.style.left = rect.left + (rect.width / 2) - (tooltip.offsetWidth / 2) + 'px';
        tooltip.style.top = rect.top - tooltip.offsetHeight - 8 + 'px';
        
        setTimeout(() => tooltip.classList.add('show'), 10);
        
        trigger._tooltip = tooltip;
    },
    
    hideTooltip(event) {
        const trigger = event.target;
        const tooltip = trigger._tooltip;
        
        if (tooltip) {
            tooltip.classList.remove('show');
            setTimeout(() => tooltip.remove(), 300);
            delete trigger._tooltip;
        }
    },
    
    initializeDropdowns() {
        const dropdownTriggers = document.querySelectorAll('[data-dropdown]');
        dropdownTriggers.forEach(trigger => {
            trigger.addEventListener('click', this.toggleDropdown.bind(this));
        });
        
        // Close dropdowns when clicking outside
        document.addEventListener('click', (event) => {
            if (!event.target.closest('[data-dropdown]')) {
                this.closeAllDropdowns();
            }
        });
    },
    
    toggleDropdown(event) {
        const trigger = event.currentTarget;
        const dropdownId = trigger.dataset.dropdown;
        const dropdown = document.getElementById(dropdownId);
        
        if (dropdown) {
            event.stopPropagation();
            
            // Close other dropdowns
            this.closeAllDropdowns();
            
            dropdown.classList.toggle('show');
            trigger.classList.toggle('active');
        }
    },
    
    closeAllDropdowns() {
        document.querySelectorAll('.dropdown.show').forEach(dropdown => {
            dropdown.classList.remove('show');
        });
        document.querySelectorAll('[data-dropdown].active').forEach(trigger => {
            trigger.classList.remove('active');
        });
    },
    
    initializeDatePickers() {
        const dateInputs = document.querySelectorAll('input[type="date"]');
        dateInputs.forEach(input => {
            // Set max date to today for fields that shouldn't allow future dates
            if (input.dataset.maxDate === 'today') {
                input.max = new Date().toISOString().split('T')[0];
            }
        });
    },
    
    initializeCharts() {
        // Chart.js initialization would go here
        // This is a placeholder for chart initialization
        if (typeof Chart !== 'undefined') {
            this.setupSalesChart();
            this.setupStockChart();
        }
    },
    
    setupSalesChart() {
        const ctx = document.getElementById('salesChart');
        if (ctx) {
            // Sales chart configuration
            console.log('Sales chart initialized');
        }
    },
    
    setupStockChart() {
        const ctx = document.getElementById('stockChart');
        if (ctx) {
            // Stock chart configuration
            console.log('Stock chart initialized');
        }
    },
    
    updateChartsTheme(isDarkMode) {
        // Update chart colors based on theme
        console.log('Charts theme updated:', isDarkMode);
    },
    
    initializeDataTables() {
        const tables = document.querySelectorAll('.data-table[data-datatable]');
        tables.forEach(table => {
            this.enhanceTable(table);
        });
    },
    
    enhanceTable(table) {
        // Add pagination, search, and export functionality
        console.log('Data table enhanced:', table);
    },
    
    // ===== UTILITY FUNCTIONS =====
    debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    },
    
    formatCurrency(amount, currency = 'NGN') {
        return new Intl.NumberFormat('en-NG', {
            style: 'currency',
            currency: currency
        }).format(amount);
    },
    
    formatDate(date, options = {}) {
        const defaultOptions = {
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        };
        
        return new Intl.DateTimeFormat('en-NG', { ...defaultOptions, ...options }).format(new Date(date));
    },
    
    formatNumber(number) {
        return new Intl.NumberFormat('en-NG').format(number);
    },
    
    copyToClipboard(text) {
        navigator.clipboard.writeText(text).then(() => {
            this.showNotification('Copied to clipboard', 'success', 2000);
        }).catch(err => {
            console.error('Failed to copy:', err);
            this.showNotification('Failed to copy to clipboard', 'error');
        });
    },
    
    downloadFile(url, filename) {
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    },
    
    printElement(elementId) {
        const element = document.getElementById(elementId);
        if (element) {
            const printWindow = window.open('', '_blank');
            const printDoc = printWindow.document;
            
            // Use DOM manipulation instead of document.write for better performance
            printDoc.open();
            printDoc.write('<!DOCTYPE html><html><head><title>Print</title>');
            printDoc.write('<style>body { font-family: Arial, sans-serif; margin: 20px; } table { width: 100%; border-collapse: collapse; } th, td { border: 1px solid #ddd; padding: 8px; text-align: left; } th { background-color: #f5f5f5; }</style>');
            printDoc.write('</head><body>');
            printDoc.write(element.innerHTML);
            printDoc.write('</body></html>');
            printDoc.close();
            
            // Allow document to render before printing
            setTimeout(() => {
                printWindow.print();
            }, 250);
        }
    }
};

// ===== INITIALIZATION =====
document.addEventListener('DOMContentLoaded', () => {
    MerrylayApp.init();
});

// ===== EXPORT FOR EXTERNAL USE =====
window.MerrylayApp = MerrylayApp;
