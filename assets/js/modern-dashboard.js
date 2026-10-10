/**
 * Modern Dashboard JavaScript - Premium Interactive Features
 * Enhanced with micro-interactions, animations, and advanced functionality
 */

class ModernDashboard {
    constructor() {
        this.init();
        this.setupEventListeners();
        this.initializeAnimations();
        this.setupRealTimeUpdates();
        this.initializeCharts();
        this.setupKeyboardShortcuts();
        this.initializeTheme();
    }

    init() {
        // Initialize dashboard components
        this.sidebar = document.getElementById('sidebar');
        this.mainContent = document.getElementById('mainContent');
        this.searchInput = document.getElementById('globalSearch');
        this.notificationBtn = document.getElementById('notificationBtn');
        this.themeToggle = document.getElementById('themeToggle');
        this.sidebarToggle = document.getElementById('sidebarToggle');
        this.mobileMenuToggle = document.getElementById('mobileMenuToggle');
        
        // Chart instances
        this.charts = {};
        
        // State management
        this.state = {
            sidebarCollapsed: false,
            theme: localStorage.getItem('theme') || 'light',
            notifications: [],
            searchResults: [],
            isLoading: false
        };

        // Performance monitoring
        this.performanceMetrics = {
            lastUpdate: Date.now(),
            updateInterval: 30000, // 30 seconds
            animationFrameId: null
        };
    }

    setupEventListeners() {
        // Sidebar toggle
        if (this.sidebarToggle) {
            this.sidebarToggle.addEventListener('click', () => this.toggleSidebar());
        }

        // Mobile menu toggle
        if (this.mobileMenuToggle) {
            this.mobileMenuToggle.addEventListener('click', () => this.toggleMobileSidebar());
        }

        // Theme toggle
        if (this.themeToggle) {
            this.themeToggle.addEventListener('click', () => this.toggleTheme());
        }

        // Search functionality
        if (this.searchInput) {
            this.searchInput.addEventListener('input', (e) => this.handleSearch(e.target.value));
            this.searchInput.addEventListener('focus', () => this.showSearchSuggestions());
            this.searchInput.addEventListener('blur', () => this.hideSearchSuggestions());
        }

        // Notifications
        if (this.notificationBtn) {
            this.notificationBtn.addEventListener('click', () => this.toggleNotifications());
        }

        // Window resize
        window.addEventListener('resize', () => this.handleResize());

        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => this.handleKeyboardShortcuts(e));

        // Click outside to close dropdowns
        document.addEventListener('click', (e) => this.handleOutsideClick(e));

        // Scroll effects
        window.addEventListener('scroll', () => this.handleScroll());

        // Visibility change for performance optimization
        document.addEventListener('visibilitychange', () => this.handleVisibilityChange());
    }

    initializeAnimations() {
        // Animate stats on page load
        this.animateStats();
        
        // Animate cards on scroll
        this.setupScrollAnimations();
        
        // Initialize particle effects
        this.initializeParticles();
        
        // Setup hover effects
        this.setupHoverEffects();
    }

    animateStats() {
        const statValues = document.querySelectorAll('.stat-value');
        statValues.forEach(element => {
            const value = parseInt(element.textContent.replace(/,/g, ''));
            if (!isNaN(value)) {
                this.animateValue(element, 0, value, 2000);
            }
        });
    }

    animateValue(element, start, end, duration) {
        const startTime = performance.now();
        const step = (currentTime) => {
            const elapsed = currentTime - startTime;
            const progress = Math.min(elapsed / duration, 1);
            
            // Easing function for smooth animation
            const easeOutQuart = 1 - Math.pow(1 - progress, 4);
            const current = Math.floor(easeOutQuart * (end - start) + start);
            
            element.textContent = current.toLocaleString();
            
            if (progress < 1) {
                requestAnimationFrame(step);
            } else {
                // Add completion animation
                element.style.transform = 'scale(1.1)';
                setTimeout(() => {
                    element.style.transform = 'scale(1)';
                }, 200);
            }
        };
        
        requestAnimationFrame(step);
    }

    setupScrollAnimations() {
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                    observer.unobserve(entry.target);
                }
            });
        }, observerOptions);

        // Observe all cards
        document.querySelectorAll('.stat-card, .content-card').forEach(card => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            card.style.transition = 'all 0.6s ease-out';
            observer.observe(card);
        });
    }

    initializeParticles() {
        // Create floating particles for visual enhancement
        const particleContainer = document.createElement('div');
        particleContainer.className = 'particles-container';
        particleContainer.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 0;
            overflow: hidden;
        `;
        
        document.body.appendChild(particleContainer);

        // Create particles
        for (let i = 0; i < 20; i++) {
            this.createParticle(particleContainer);
        }
    }

    createParticle(container) {
        const particle = document.createElement('div');
        const size = Math.random() * 4 + 2;
        const duration = Math.random() * 20 + 10;
        const delay = Math.random() * 5;
        
        particle.style.cssText = `
            position: absolute;
            width: ${size}px;
            height: ${size}px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            left: ${Math.random() * 100}%;
            top: 100%;
            animation: float-up ${duration}s linear ${delay}s infinite;
        `;
        
        container.appendChild(particle);
    }

    setupHoverEffects() {
        // Enhanced hover effects for interactive elements
        const interactiveElements = document.querySelectorAll('.nav-link, .stat-card, .content-card, .action-btn, .icon-btn');
        
        interactiveElements.forEach(element => {
            element.addEventListener('mouseenter', (e) => this.handleHoverEnter(e));
            element.addEventListener('mouseleave', (e) => this.handleHoverLeave(e));
        });
    }

    handleHoverEnter(e) {
        const element = e.currentTarget;
        
        // Add ripple effect
        this.createRipple(element, e);
        
        // Scale effect
        element.style.transform = element.style.transform || '';
        if (element.classList.contains('stat-card')) {
            element.style.transform = 'translateY(-8px) scale(1.02)';
        } else if (element.classList.contains('nav-link')) {
            element.style.transform = 'translateX(5px)';
        } else {
            element.style.transform = 'translateY(-2px) scale(1.05)';
        }
    }

    handleHoverLeave(e) {
        const element = e.currentTarget;
        
        // Reset transform
        setTimeout(() => {
            element.style.transform = '';
        }, 200);
    }

    createRipple(element, event) {
        const ripple = document.createElement('span');
        const rect = element.getBoundingClientRect();
        const size = Math.max(rect.width, rect.height);
        const x = event.clientX - rect.left - size / 2;
        const y = event.clientY - rect.top - size / 2;
        
        ripple.style.cssText = `
            position: absolute;
            width: ${size}px;
            height: ${size}px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.3);
            left: ${x}px;
            top: ${y}px;
            pointer-events: none;
            transform: scale(0);
            animation: ripple 0.6s ease-out;
        `;
        
        element.style.position = 'relative';
        element.style.overflow = 'hidden';
        element.appendChild(ripple);
        
        setTimeout(() => ripple.remove(), 600);
    }

    toggleSidebar() {
        this.state.sidebarCollapsed = !this.state.sidebarCollapsed;
        
        if (this.sidebar) {
            this.sidebar.classList.toggle('collapsed');
        }
        
        if (this.mainContent) {
            this.mainContent.classList.toggle('expanded');
        }
        
        // Update icon
        const icon = this.sidebarToggle?.querySelector('i');
        if (icon) {
            if (this.state.sidebarCollapsed) {
                icon.classList.remove('fa-bars');
                icon.classList.add('fa-chevron-right');
            } else {
                icon.classList.remove('fa-chevron-right');
                icon.classList.add('fa-bars');
            }
        }
        
        // Save preference
        localStorage.setItem('sidebarCollapsed', this.state.sidebarCollapsed);
        
        // Trigger resize for charts
        setTimeout(() => this.resizeCharts(), 300);
    }

    toggleMobileSidebar() {
        if (this.sidebar) {
            this.sidebar.classList.toggle('mobile-open');
        }
    }

    toggleTheme() {
        this.state.theme = this.state.theme === 'light' ? 'dark' : 'light';
        document.documentElement.setAttribute('data-theme', this.state.theme);
        
        // Update icon
        const icon = this.themeToggle?.querySelector('i');
        if (icon) {
            if (this.state.theme === 'dark') {
                icon.classList.remove('fa-moon');
                icon.classList.add('fa-sun');
            } else {
                icon.classList.remove('fa-sun');
                icon.classList.add('fa-moon');
            }
        }
        
        // Save preference
        localStorage.setItem('theme', this.state.theme);
        
        // Update chart themes
        this.updateChartThemes();
    }

    initializeTheme() {
        const savedTheme = localStorage.getItem('theme') || 'light';
        this.state.theme = savedTheme;
        document.documentElement.setAttribute('data-theme', savedTheme);
        
        // Update icon
        const icon = this.themeToggle?.querySelector('i');
        if (icon && savedTheme === 'dark') {
            icon.classList.remove('fa-moon');
            icon.classList.add('fa-sun');
        }
    }

    handleSearch(query) {
        if (query.length < 2) {
            this.hideSearchSuggestions();
            return;
        }
        
        // Debounce search
        clearTimeout(this.searchTimeout);
        this.searchTimeout = setTimeout(() => {
            this.performSearch(query);
        }, 300);
    }

    performSearch(query) {
        this.state.isLoading = true;
        
        // Simulate API call
        setTimeout(() => {
            this.state.searchResults = this.generateMockSearchResults(query);
            this.showSearchResults();
            this.state.isLoading = false;
        }, 300);
    }

    generateMockSearchResults(query) {
        const mockData = [
            { type: 'product', name: 'Laptop Computer', url: '../products/view.php' },
            { type: 'product', name: 'Office Chair', url: '../products/view.php' },
            { type: 'sale', name: 'Sale #12345', url: '../dispatch/daily/index.php' },
            { type: 'customer', name: 'John Doe', url: '../customers/view.php' },
            { type: 'dispatch', name: 'Dispatch #67890', url: '../dispatch/record.php' }
        ];
        
        return mockData.filter(item => 
            item.name.toLowerCase().includes(query.toLowerCase())
        ).slice(0, 5);
    }

    showSearchSuggestions() {
        // Create search suggestions dropdown
        let suggestionsDropdown = document.getElementById('searchSuggestions');
        
        if (!suggestionsDropdown) {
            suggestionsDropdown = document.createElement('div');
            suggestionsDropdown.id = 'searchSuggestions';
            suggestionsDropdown.className = 'search-suggestions';
            suggestionsDropdown.style.cssText = `
                position: absolute;
                top: 100%;
                left: 0;
                right: 0;
                background: var(--glass-bg);
                backdrop-filter: blur(20px);
                border: 1px solid var(--glass-border);
                border-radius: var(--radius-xl);
                margin-top: var(--space-sm);
                max-height: 300px;
                overflow-y: auto;
                z-index: 1000;
                display: none;
            `;
            
            this.searchInput.parentElement.appendChild(suggestionsDropdown);
        }
        
        suggestionsDropdown.style.display = 'block';
    }

    hideSearchSuggestions() {
        setTimeout(() => {
            const suggestionsDropdown = document.getElementById('searchSuggestions');
            if (suggestionsDropdown) {
                suggestionsDropdown.style.display = 'none';
            }
        }, 200);
    }

    showSearchResults() {
        const suggestionsDropdown = document.getElementById('searchSuggestions');
        if (!suggestionsDropdown) return;
        
        suggestionsDropdown.innerHTML = '';
        
        if (this.state.searchResults.length === 0) {
            suggestionsDropdown.innerHTML = `
                <div class="p-3 text-center text-muted">
                    <i class="fas fa-search me-2"></i>No results found
                </div>
            `;
        } else {
            this.state.searchResults.forEach(result => {
                const item = document.createElement('div');
                item.className = 'search-result-item';
                item.style.cssText = `
                    padding: var(--space-md) var(--space-lg);
                    cursor: pointer;
                    transition: all var(--transition-fast);
                    border-bottom: 1px solid var(--glass-border);
                `;
                
                item.innerHTML = `
                    <div class="d-flex align-items-center gap-2">
                        <i class="fas fa-${this.getItemIcon(result.type)} text-muted"></i>
                        <div>
                            <div class="fw-semibold">${result.name}</div>
                            <small class="text-muted text-capitalize">${result.type}</small>
                        </div>
                    </div>
                `;
                
                item.addEventListener('click', () => {
                    window.location.href = result.url;
                });
                
                item.addEventListener('mouseenter', () => {
                    item.style.background = 'rgba(99, 102, 241, 0.1)';
                });
                
                item.addEventListener('mouseleave', () => {
                    item.style.background = 'transparent';
                });
                
                suggestionsDropdown.appendChild(item);
            });
        }
    }

    getItemIcon(type) {
        const icons = {
            product: 'box',
            sale: 'receipt',
            customer: 'user',
            dispatch: 'truck'
        };
        return icons[type] || 'file';
    }

    toggleNotifications() {
        // Create notifications panel
        let notificationsPanel = document.getElementById('notificationsPanel');
        
        if (!notificationsPanel) {
            notificationsPanel = this.createNotificationsPanel();
        }
        
        notificationsPanel.classList.toggle('show');
    }

    createNotificationsPanel() {
        const panel = document.createElement('div');
        panel.id = 'notificationsPanel';
        panel.className = 'notifications-panel';
        panel.style.cssText = `
            position: absolute;
            top: 100%;
            right: 0;
            width: 350px;
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius-xl);
            margin-top: var(--space-sm);
            z-index: 1000;
            display: none;
            box-shadow: var(--shadow-2xl);
        `;
        
        // Mock notifications
        const notifications = [
            { type: 'warning', title: 'Low Stock', message: '5 products are running low on stock', time: '2 min ago' },
            { type: 'success', title: 'New Sale', message: 'Sale #12345 completed successfully', time: '5 min ago' },
            { type: 'info', title: 'System Update', message: 'Dashboard features have been updated', time: '1 hour ago' }
        ];
        
        panel.innerHTML = `
            <div class="p-3 border-bottom border-glass">
                <h6 class="mb-0">Notifications</h6>
            </div>
            <div class="notifications-list">
                ${notifications.map(notif => `
                    <div class="notification-item p-3 border-bottom border-glass" style="cursor: pointer; transition: all var(--transition-fast);">
                        <div class="d-flex gap-3">
                            <div class="notification-icon flex-shrink-0">
                                <div class="w-10 h-10 rounded-circle d-flex align-items-center justify-content-center" 
                                     style="background: ${this.getNotificationColor(notif.type)};">
                                    <i class="fas fa-${this.getNotificationIcon(notif.type)} text-white"></i>
                                </div>
                            </div>
                            <div class="flex-grow-1">
                                <div class="fw-semibold">${notif.title}</div>
                                <small class="text-muted">${notif.message}</small>
                                <div class="text-muted small mt-1">${notif.time}</div>
                            </div>
                        </div>
                    </div>
                `).join('')}
            </div>
            <div class="p-3">
                <button class="btn btn-sm btn-outline-primary w-100">View All Notifications</button>
            </div>
        `;
        
        this.notificationBtn.parentElement.appendChild(panel);
        
        // Add hover effects
        panel.querySelectorAll('.notification-item').forEach(item => {
            item.addEventListener('mouseenter', () => {
                item.style.background = 'rgba(99, 102, 241, 0.05)';
            });
            item.addEventListener('mouseleave', () => {
                item.style.background = 'transparent';
            });
        });
        
        return panel;
    }

    getNotificationColor(type) {
        const colors = {
            warning: 'var(--gradient-warning)',
            success: 'var(--gradient-success)',
            info: 'var(--gradient-primary)',
            danger: 'var(--gradient-danger)'
        };
        return colors[type] || colors.info;
    }

    getNotificationIcon(type) {
        const icons = {
            warning: 'exclamation-triangle',
            success: 'check-circle',
            info: 'info-circle',
            danger: 'times-circle'
        };
        return icons[type] || icons.info;
    }

    handleResize() {
        // Handle responsive behavior
        if (window.innerWidth > 768) {
            if (this.sidebar) {
                this.sidebar.classList.remove('mobile-open');
            }
        }
        
        // Resize charts
        this.resizeCharts();
    }

    handleKeyboardShortcuts(e) {
        // Ctrl/Cmd + K for search
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            this.searchInput?.focus();
        }
        
        // Ctrl/Cmd + / for shortcuts help
        if ((e.ctrlKey || e.metaKey) && e.key === '/') {
            e.preventDefault();
            this.showShortcutsHelp();
        }
        
        // Escape to close dropdowns
        if (e.key === 'Escape') {
            this.closeAllDropdowns();
        }
        
        // Ctrl/Cmd + B to toggle sidebar
        if ((e.ctrlKey || e.metaKey) && e.key === 'b') {
            e.preventDefault();
            this.toggleSidebar();
        }
    }

    showShortcutsHelp() {
        // Create shortcuts modal
        const modal = document.createElement('div');
        modal.className = 'shortcuts-modal';
        modal.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            backdrop-filter: blur(5px);
        `;
        
        modal.innerHTML = `
            <div class="shortcuts-content" style="
                background: var(--glass-bg);
                backdrop-filter: blur(20px);
                border: 1px solid var(--glass-border);
                border-radius: var(--radius-2xl);
                padding: var(--space-2xl);
                max-width: 500px;
                width: 90%;
                max-height: 80vh;
                overflow-y: auto;
            ">
                <h4 class="mb-3">Keyboard Shortcuts</h4>
                <div class="shortcuts-list">
                    <div class="shortcut-item mb-2">
                        <kbd class="px-2 py-1 bg-dark text-white rounded">Ctrl</kbd> + <kbd class="px-2 py-1 bg-dark text-white rounded">K</kbd>
                        <span class="ms-3">Search</span>
                    </div>
                    <div class="shortcut-item mb-2">
                        <kbd class="px-2 py-1 bg-dark text-white rounded">Ctrl</kbd> + <kbd class="px-2 py-1 bg-dark text-white rounded">B</kbd>
                        <span class="ms-3">Toggle Sidebar</span>
                    </div>
                    <div class="shortcut-item mb-2">
                        <kbd class="px-2 py-1 bg-dark text-white rounded">Ctrl</kbd> + <kbd class="px-2 py-1 bg-dark text-white rounded">/</kbd>
                        <span class="ms-3">Show Shortcuts</span>
                    </div>
                    <div class="shortcut-item mb-2">
                        <kbd class="px-2 py-1 bg-dark text-white rounded">Esc</kbd>
                        <span class="ms-3">Close Dropdowns</span>
                    </div>
                </div>
                <button class="btn btn-primary mt-3" onclick="this.closest('.shortcuts-modal').remove()">Close</button>
            </div>
        `;
        
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                modal.remove();
            }
        });
        
        document.body.appendChild(modal);
    }

    handleOutsideClick(e) {
        // Close dropdowns when clicking outside
        if (!e.target.closest('.search-bar') && !e.target.closest('#searchSuggestions')) {
            this.hideSearchSuggestions();
        }
        
        if (!e.target.closest('.user-menu') && !e.target.closest('#notificationsPanel')) {
            const notificationsPanel = document.getElementById('notificationsPanel');
            if (notificationsPanel) {
                notificationsPanel.classList.remove('show');
            }
        }
        
        // Close mobile sidebar when clicking outside
        if (window.innerWidth <= 768 && !e.target.closest('.sidebar') && !e.target.closest('.position-fixed')) {
            if (this.sidebar) {
                this.sidebar.classList.remove('mobile-open');
            }
        }
    }

    handleScroll() {
        // Add scroll effects
        const scrollTop = window.pageYOffset;
        
        // Parallax effect for background
        const background = document.querySelector('body::before');
        if (background) {
            const speed = 0.5;
            const yPos = -(scrollTop * speed);
            document.body.style.backgroundPositionY = `${yPos}px`;
        }
    }

    handleVisibilityChange() {
        if (document.hidden) {
            // Pause animations when page is not visible
            this.pauseAnimations();
        } else {
            // Resume animations when page is visible
            this.resumeAnimations();
        }
    }

    pauseAnimations() {
        // Clear real-time updates
        if (this.updateInterval) {
            clearInterval(this.updateInterval);
        }
        
        // Cancel animation frames
        if (this.performanceMetrics.animationFrameId) {
            cancelAnimationFrame(this.performanceMetrics.animationFrameId);
        }
    }

    resumeAnimations() {
        // Resume real-time updates
        this.setupRealTimeUpdates();
    }

    setupRealTimeUpdates() {
        // Update clock
        this.updateClock();
        setInterval(() => this.updateClock(), 1000);
        
        // Simulate real-time data updates
        this.updateInterval = setInterval(() => {
            this.updateRealTimeData();
        }, this.performanceMetrics.updateInterval);
    }

    updateClock() {
        const clockElement = document.getElementById('currentTime');
        if (clockElement) {
            const now = new Date();
            clockElement.textContent = now.toLocaleTimeString('en-US', { 
                hour: '2-digit', 
                minute: '2-digit',
                hour12: true 
            });
        }
    }

    updateRealTimeData() {
        // Simulate real-time updates for charts and stats
        if (this.charts.salesChart) {
            this.updateSalesChartRealtime();
        }
        
        // Check for new notifications
        this.checkForNewNotifications();
    }

    updateSalesChartRealtime() {
        // Add new data point to sales chart
        const chart = this.charts.salesChart;
        if (chart && chart.data.datasets[0]) {
            const dataset = chart.data.datasets[0];
            const lastValue = dataset.data[dataset.data.length - 1];
            const change = (Math.random() - 0.5) * lastValue * 0.05; // ±5% change
            const newValue = Math.max(0, lastValue + change);
            
            dataset.data[dataset.data.length - 1] = newValue;
            chart.update('none'); // Update without animation for smooth real-time effect
        }
    }

    checkForNewNotifications() {
        // Simulate checking for new notifications
        if (Math.random() > 0.8) { // 20% chance of new notification
            this.addNotification({
                type: 'info',
                title: 'New Activity',
                message: 'Recent activity detected in your store',
                time: 'Just now'
            });
        }
    }

    addNotification(notification) {
        this.state.notifications.unshift(notification);
        
        // Update notification dot
        const notificationDot = document.querySelector('.notification-dot');
        if (notificationDot) {
            notificationDot.style.display = 'block';
            notificationDot.style.animation = 'pulse 2s infinite';
        }
    }

    initializeCharts() {
        // Initialize all charts with enhanced configurations
        this.initializeSalesChart();
        this.initializeStockChart();
        this.initializeCategoryChart();
        this.initializePaymentChart();
    }

    initializeSalesChart() {
        const ctx = document.getElementById('salesChart');
        if (!ctx) return;
        
        this.charts.salesChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
                datasets: [{
                    label: 'Sales Revenue',
                    data: [12000, 19000, 15000, 25000, 22000, 30000, 28000],
                    borderColor: '#6366f1',
                    backgroundColor: 'rgba(99, 102, 241, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#6366f1',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 6,
                    pointHoverRadius: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        labels: {
                            usePointStyle: true,
                            padding: 15,
                            font: {
                                size: 12,
                                weight: '600'
                            }
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        padding: 12,
                        cornerRadius: 8,
                        titleFont: {
                            size: 14,
                            weight: '600'
                        },
                        bodyFont: {
                            size: 12
                        },
                        callbacks: {
                            label: function(context) {
                                return 'Revenue: ₦' + context.parsed.y.toLocaleString();
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '₦' + value.toLocaleString();
                            },
                            font: {
                                size: 11
                            }
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            font: {
                                size: 11
                            }
                        }
                    }
                }
            }
        });
    }

    initializeStockChart() {
        const ctx = document.getElementById('stockChart');
        if (!ctx) return;
        
        this.charts.stockChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: ['Electronics', 'Clothing', 'Food', 'Books', 'Sports', 'Toys'],
                datasets: [{
                    label: 'In Stock',
                    data: [120, 89, 156, 78, 92, 65],
                    backgroundColor: '#10b981',
                    borderRadius: 8,
                    barPercentage: 0.7
                }, {
                    label: 'Low Stock',
                    data: [12, 8, 5, 15, 6, 9],
                    backgroundColor: '#f59e0b',
                    borderRadius: 8,
                    barPercentage: 0.7
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            usePointStyle: true,
                            padding: 15,
                            font: {
                                size: 12,
                                weight: '600'
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        stacked: true,
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        stacked: true,
                        ticks: {
                            callback: function(value) {
                                return value + ' items';
                            },
                            font: {
                                size: 11
                            }
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    }
                }
            }
        });
    }

    initializeCategoryChart() {
        const ctx = document.getElementById('categoryChart');
        if (!ctx) return;
        
        this.charts.categoryChart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Electronics', 'Clothing', 'Food', 'Books', 'Sports', 'Toys'],
                datasets: [{
                    data: [35, 25, 20, 10, 7, 3],
                    backgroundColor: [
                        '#6366f1',
                        '#10b981',
                        '#f59e0b',
                        '#ef4444',
                        '#3b82f6',
                        '#8b5cf6'
                    ],
                    borderWidth: 0,
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            usePointStyle: true,
                            padding: 15,
                            font: {
                                size: 11,
                                weight: '600'
                            }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.label + ': ' + context.parsed + '%';
                            }
                        }
                    }
                }
            }
        });
    }

    initializePaymentChart() {
        const ctx = document.getElementById('paymentChart');
        if (!ctx) return;
        
        this.charts.paymentChart = new Chart(ctx, {
            type: 'polarArea',
            data: {
                labels: ['Cash', 'Card', 'Transfer', 'Mobile'],
                datasets: [{
                    data: [45, 30, 20, 5],
                    backgroundColor: [
                        'rgba(99, 102, 241, 0.8)',
                        'rgba(16, 185, 129, 0.8)',
                        'rgba(245, 158, 11, 0.8)',
                        'rgba(59, 130, 246, 0.8)'
                    ],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            usePointStyle: true,
                            padding: 15,
                            font: {
                                size: 11,
                                weight: '600'
                            }
                        }
                    }
                },
                scales: {
                    r: {
                        beginAtZero: true,
                        ticks: {
                            display: false
                        }
                    }
                }
            }
        });
    }

    resizeCharts() {
        // Resize all charts
        Object.values(this.charts).forEach(chart => {
            if (chart) {
                chart.resize();
            }
        });
    }

    updateChartThemes() {
        // Update chart colors based on theme
        const isDark = this.state.theme === 'dark';
        const textColor = isDark ? '#f1f5f9' : '#1e293b';
        const gridColor = isDark ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.05)';
        
        Object.values(this.charts).forEach(chart => {
            if (chart) {
                // Update text colors
                chart.options.plugins.legend.labels.color = textColor;
                chart.options.plugins.tooltip.titleColor = textColor;
                chart.options.plugins.tooltip.bodyColor = textColor;
                
                // Update grid colors
                if (chart.options.scales?.y) {
                    chart.options.scales.y.ticks.color = textColor;
                    chart.options.scales.y.grid.color = gridColor;
                }
                if (chart.options.scales?.x) {
                    chart.options.scales.x.ticks.color = textColor;
                    chart.options.scales.x.grid.color = gridColor;
                }
                
                chart.update();
            }
        });
    }

    closeAllDropdowns() {
        this.hideSearchSuggestions();
        
        const notificationsPanel = document.getElementById('notificationsPanel');
        if (notificationsPanel) {
            notificationsPanel.classList.remove('show');
        }
        
        if (this.sidebar && window.innerWidth <= 768) {
            this.sidebar.classList.remove('mobile-open');
        }
    }

    // Performance optimization
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
    }

    throttle(func, limit) {
        let inThrottle;
        return function() {
            const args = arguments;
            const context = this;
            if (!inThrottle) {
                func.apply(context, args);
                inThrottle = true;
                setTimeout(() => inThrottle = false, limit);
            }
        };
    }
}

// Add CSS animations
const style = document.createElement('style');
style.textContent = `
    @keyframes float-up {
        0% {
            transform: translateY(100vh) rotate(0deg);
            opacity: 0;
        }
        10% {
            opacity: 1;
        }
        90% {
            opacity: 1;
        }
        100% {
            transform: translateY(-100vh) rotate(360deg);
            opacity: 0;
        }
    }
    
    @keyframes ripple {
        0% {
            transform: scale(0);
            opacity: 1;
        }
        100% {
            transform: scale(4);
            opacity: 0;
        }
    }
    
    .search-suggestions,
    .notifications-panel {
        animation: slideDown 0.3s ease-out;
    }
    
    @keyframes slideDown {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    .shortcuts-modal {
        animation: fadeIn 0.3s ease-out;
    }
    
    @keyframes fadeIn {
        from {
            opacity: 0;
        }
        to {
            opacity: 1;
        }
    }
    
    .kbd {
        font-family: 'SF Mono', 'Monaco', 'Inconsolata', 'Roboto Mono', monospace;
        font-size: 0.75rem;
        font-weight: 600;
        background: linear-gradient(180deg, #fafafa 0%, #e0e0e0 100%);
        border: 1px solid #ccc;
        border-radius: 4px;
        box-shadow: 0 2px 0 #ccc;
        padding: 2px 6px;
    }
`;
document.head.appendChild(style);

// Initialize dashboard when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    window.dashboard = new ModernDashboard();
});

// Export for module usage
if (typeof module !== 'undefined' && module.exports) {
    module.exports = ModernDashboard;
}
