/**
 * System-wide Theme Management
 */

(function() {
    // Check if we are on the landing page (index.php or /)
    const isLandingPage = window.location.pathname.endsWith('index.php') || 
                         window.location.pathname.endsWith('/') || 
                         window.location.pathname === '';

    if (isLandingPage) {
        document.documentElement.classList.remove('dark');
        document.body && document.body.classList.remove('dark-mode');
        return;
    }

    // Determine if we should use dark mode
    // Rule: Must be logged in AND have the preference enabled in session
    // fallback to localStorage ONLY if we want to support dark mode on login page (user said no)
    
    // Determine user theme preference:
    // 1. If logged in, check sessionThemePref: 0 = light, 1 = dark, 2 = system.
    // 2. Otherwise check localStorage 'theme-pref': 'light', 'dark', 'system'.
    // 3. Fallback to 'system'.
    
    let pref = 'system';
    
    if (typeof isLoggedIn !== 'undefined' && isLoggedIn && typeof sessionThemePref !== 'undefined' && sessionThemePref !== null) {
        if (sessionThemePref === 0) pref = 'light';
        else if (sessionThemePref === 1) pref = 'dark';
        else if (sessionThemePref === 2) pref = 'system';
        
        // Sync with localStorage
        localStorage.setItem('theme-pref', pref);
    } else {
        const storedPref = localStorage.getItem('theme-pref');
        if (storedPref === 'light' || storedPref === 'dark' || storedPref === 'system') {
            pref = storedPref;
        } else {
            pref = 'system';
            localStorage.setItem('theme-pref', pref);
        }
    }

    // Function to apply theme based on active preference
    window.applyTheme = function(themePref) {
        let isDark = false;
        if (themePref === 'dark') {
            isDark = true;
        } else if (themePref === 'light') {
            isDark = false;
        } else {
            // 'system' preference: check media query
            isDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
        }

        // Apply dark mode classes
        if (isDark) {
            document.documentElement.classList.add('dark');
            if (document.body) {
                document.body.classList.add('dark-mode');
            } else {
                document.addEventListener('DOMContentLoaded', () => {
                    document.body.classList.add('dark-mode');
                });
            }
        } else {
            document.documentElement.classList.remove('dark');
            if (document.body) {
                document.body.classList.remove('dark-mode');
            } else {
                document.addEventListener('DOMContentLoaded', () => {
                    document.body.classList.remove('dark-mode');
                });
            }
        }

        // Update active theme classes or indicators if any exist
        updateThemeTogglerUI(themePref);
    };

    // Initialize immediately
    window.applyTheme(pref);

    // Watch for system color scheme changes if preference is 'system'
    if (window.matchMedia) {
        const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
        const listener = (e) => {
            const currentPref = localStorage.getItem('theme-pref') || 'system';
            if (currentPref === 'system') {
                window.applyTheme('system');
            }
        };
        
        // Support both modern addEventListener and legacy addListener
        if (mediaQuery.addEventListener) {
            mediaQuery.addEventListener('change', listener);
        } else if (mediaQuery.addListener) {
            mediaQuery.addListener(listener);
        }
    }
    
    // Also make sure we re-apply when DOM is fully loaded to ensure body exists
    document.addEventListener('DOMContentLoaded', () => {
        const currentPref = localStorage.getItem('theme-pref') || 'system';
        window.applyTheme(currentPref);
    });
})();

/**
 * Cycle through theme preferences: light -> dark -> system -> light
 */
function cycleThemePref() {
    const currentPref = localStorage.getItem('theme-pref') || 'system';
    let newPref = 'light';
    
    if (currentPref === 'light') {
        newPref = 'dark';
    } else if (currentPref === 'dark') {
        newPref = 'system';
    } else {
        newPref = 'light';
    }
    
    localStorage.setItem('theme-pref', newPref);
    window.applyTheme(newPref);
    
    // Sync with backend if logged in
    if (typeof isLoggedIn !== 'undefined' && isLoggedIn) {
        let isDarkModeVal = 0;
        if (newPref === 'light') isDarkModeVal = 0;
        else if (newPref === 'dark') isDarkModeVal = 1;
        else if (newPref === 'system') isDarkModeVal = 2;
        
        const base = typeof apiBasePath !== 'undefined' ? apiBasePath : '../';
        
        fetch(base + 'api/profile_update.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'is_darkmode=' + isDarkModeVal
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                console.log('Theme preference synced to DB: ' + newPref);
            }
        })
        .catch(err => console.error('Failed to sync theme with backend:', err));
    }
    
    return newPref;
}

/**
 * Update the Toggler Button icons and text dynamically
 */
function updateThemeTogglerUI(pref) {
    // Find all theme toggler elements (can be multiple on desktop and mobile)
    const togglers = document.querySelectorAll('.theme-toggler-btn');
    togglers.forEach(btn => {
        // We will customize the button content based on the theme
        const iconSpan = btn.querySelector('.theme-toggler-icon');
        const textSpan = btn.querySelector('.theme-toggler-text');
        
        let iconHtml = '';
        let labelText = '';
        
        if (pref === 'light') {
            // Sun Icon
            iconHtml = `<svg class="w-5 h-5 text-amber-500 animate-[spin_8s_linear_infinite]" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                <circle cx="12" cy="12" r="5"></circle>
                <line x1="12" y1="1" x2="12" y2="3"></line>
                <line x1="12" y1="21" x2="12" y2="23"></line>
                <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
                <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
                <line x1="1" y1="12" x2="3" y2="12"></line>
                <line x1="21" y1="12" x2="23" y2="12"></line>
                <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
                <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
            </svg>`;
            labelText = 'Light';
        } else if (pref === 'dark') {
            // Moon Icon
            iconHtml = `<svg class="w-5 h-5 text-indigo-400 animate-pulse" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
            </svg>`;
            labelText = 'Dark';
        } else {
            // Monitor / System Icon
            iconHtml = `<svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                <rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
                <line x1="8" y1="21" x2="16" y2="21"></line>
                <line x1="12" y1="17" x2="12" y2="21"></line>
            </svg>`;
            labelText = 'Auto';
        }
        
        if (iconSpan) iconSpan.innerHTML = iconHtml;
        if (textSpan) textSpan.innerText = labelText;
        
        // Also update title tooltip
        btn.setAttribute('title', `Theme: ${labelText} (Click to change)`);
    });
}
