/**
 * Clean Dashboard JavaScript - Professional & Structured
 * Minimal, functional, and well-organized
 */

class CleanDashboard {
    constructor() {
        this.init();
        this.setupEventListeners();
        this.initializeCharts();
    }

    init() {
        // Initialize elements
        this.sidebar = document.getElementById('sidebar');
        this.mainContent = document.getElementById('mainContent');
        this.searchInput = document.getElementById('globalSearch');
        this.sidebarToggle = document.getElementById('sidebarToggle');
        this.mobileMenuToggle = document.getElementById('mobileMenuToggle');
        
        // State
        this.sidebarCollapsed = false;
        this.charts = {};
    }

    setupEventListeners() {
        // Sidebar toggle
        if (this.sidebarToggle) {
            this.sidebarToggle.addEventListener('click', () => this.toggleSidebar());
        }

        // Mobile menu
        if (this.mobileMenuToggle) {
            this.mobileMenuToggle.addEventListener('click', () => this.toggleMobileSidebar());
        }

        // Search
        if (this.searchInput) {
            this.searchInput.addEventListener('input', (e) => this.handleSearch(e.target.value));
        }

        // Window resize
        window.addEventListener('resize', () => this.handleResize());

        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => this.handleKeyboardShortcuts(e));

        // Click outside for mobile
        document.addEventListener('click', (e) => this.handleOutsideClick(e));
    }

    toggleSidebar() {
        this.sidebarCollapsed = !this.sidebarCollapsed;
        
        if (this.sidebar) {
            this.sidebar.classList.toggle('collapsed');
        }
        
        if (this.mainContent) {
            this.mainContent.classList.toggle('expanded');
        }
        
        // Update icon
        const icon = this.sidebarToggle?.querySelector('i');
        if (icon) {
            if (this.sidebarCollapsed) {
                icon.classList.remove('fa-bars');
                icon.classList.add('fa-chevron-right');
            } else {
                icon.classList.remove('fa-chevron-right');
                icon.classList.add('fa-bars');
            }
        }
        
        // Resize charts after sidebar animation
        setTimeout(() => this.resizeCharts(), 250);
    }

    toggleMobileSidebar() {
        if (this.sidebar) {
            this.sidebar.classList.toggle('mobile-open');
        }
    }

    handleSearch(query) {
        if (query.length < 2) return;
        
        // Debounced search
        clearTimeout(this.searchTimeout);
        this.searchTimeout = setTimeout(() => {
            console.log('Searching for:', query);
            // Implement search functionality here
        }, 300);
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
        
        // Ctrl/Cmd + B to toggle sidebar
        if ((e.ctrlKey || e.metaKey) && e.key === 'b') {
            e.preventDefault();
            this.toggleSidebar();
        }
        
        // Escape to close mobile sidebar
        if (e.key === 'Escape' && window.innerWidth <= 768) {
            if (this.sidebar) {
                this.sidebar.classList.remove('mobile-open');
            }
        }
    }

    handleOutsideClick(e) {
        // Close mobile sidebar when clicking outside
        if (window.innerWidth <= 768 && !e.target.closest('.sidebar') && !e.target.closest('.mobile-menu-toggle')) {
            if (this.sidebar) {
                this.sidebar.classList.remove('mobile-open');
            }
        }
    }

    initializeCharts() {
        // Initialize all charts with clean, professional styling
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
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.3,
                    pointBackgroundColor: '#3b82f6',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6
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
                                weight: '500'
                            }
                        }
                    },
                    tooltip: {
                        backgroundColor: '#ffffff',
                        titleColor: '#1e293b',
                        bodyColor: '#475569',
                        borderColor: '#e2e8f0',
                        borderWidth: 1,
                        padding: 12,
                        cornerRadius: 6,
                        titleFont: {
                            size: 12,
                            weight: '600'
                        },
                        bodyFont: {
                            size: 11
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
                            },
                            color: '#64748b'
                        },
                        grid: {
                            color: '#f1f5f9',
                            drawBorder: false
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            font: {
                                size: 11
                            },
                            color: '#64748b'
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
                    borderRadius: 4,
                    barPercentage: 0.7
                }, {
                    label: 'Low Stock',
                    data: [12, 8, 5, 15, 6, 9],
                    backgroundColor: '#f59e0b',
                    borderRadius: 4,
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
                                weight: '500'
                            }
                        }
                    },
                    tooltip: {
                        backgroundColor: '#ffffff',
                        titleColor: '#1e293b',
                        bodyColor: '#475569',
                        borderColor: '#e2e8f0',
                        borderWidth: 1,
                        padding: 12,
                        cornerRadius: 6
                    }
                },
                scales: {
                    x: {
                        stacked: true,
                        grid: {
                            display: false
                        },
                        ticks: {
                            font: {
                                size: 11
                            },
                            color: '#64748b'
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
                            },
                            color: '#64748b'
                        },
                        grid: {
                            color: '#f1f5f9',
                            drawBorder: false
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
                        '#3b82f6',
                        '#10b981',
                        '#f59e0b',
                        '#ef4444',
                        '#8b5cf6',
                        '#06b6d4'
                    ],
                    borderWidth: 2,
                    borderColor: '#ffffff',
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
                                weight: '500'
                            }
                        }
                    },
                    tooltip: {
                        backgroundColor: '#ffffff',
                        titleColor: '#1e293b',
                        bodyColor: '#475569',
                        borderColor: '#e2e8f0',
                        borderWidth: 1,
                        padding: 12,
                        cornerRadius: 6,
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
                        'rgba(59, 130, 246, 0.8)',
                        'rgba(16, 185, 129, 0.8)',
                        'rgba(245, 158, 11, 0.8)',
                        'rgba(139, 92, 246, 0.8)'
                    ],
                    borderWidth: 2,
                    borderColor: '#ffffff'
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
                                weight: '500'
                            }
                        }
                    },
                    tooltip: {
                        backgroundColor: '#ffffff',
                        titleColor: '#1e293b',
                        bodyColor: '#475569',
                        borderColor: '#e2e8f0',
                        borderWidth: 1,
                        padding: 12,
                        cornerRadius: 6,
                        callbacks: {
                            label: function(context) {
                                return context.label + ': ' + context.parsed.r + ' transactions';
                            }
                        }
                    }
                },
                scales: {
                    r: {
                        beginAtZero: true,
                        ticks: {
                            display: false
                        },
                        grid: {
                            color: '#f1f5f9'
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

    // Utility functions
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

    formatCurrency(amount) {
        return new Intl.NumberFormat('en-NG', {
            style: 'currency',
            currency: 'NGN'
        }).format(amount);
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
}

// Initialize dashboard when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    window.dashboard = new CleanDashboard();
    
    // Update clock every minute
    setInterval(() => {
        window.dashboard.updateClock();
    }, 60000);
});

// Export for module usage
if (typeof module !== 'undefined' && module.exports) {
    module.exports = CleanDashboard;
}
