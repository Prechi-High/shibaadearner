const navFooter = {
  container: null,
  init(settings = { theme: 'bright', selectedColor: '#FF7A00' }) {
    if (!this.container) {
      this.container = document.createElement("footer");
      this.container.id = "nav-footer";
      document.body.appendChild(this.container);

      // Inject styles
      const style = document.createElement("style");
      style.textContent = `
        #nav-footer {
          position: fixed;
          bottom: 0;
          left: 0;
          right: 0;
          display: flex;
          justify-content: space-around;
          align-items: center;
          background-color: rgba(255, 255, 255, 0.6);
          box-shadow: 0 -1px 12px rgba(0, 0, 0, 0.1);
          padding: 8px 0;
          z-index: 9999;
          font-family: Arial, sans-serif;
          height: 55px;
          border-top: 1px solid rgba(255, 255, 255, 0.4);
          backdrop-filter: blur(10px);
          transition: background-color 0.3s ease;
        }

        #nav-footer.theme-dark {
          background-color: rgba(30, 30, 30, 0.7);
          border-top: 1px solid rgba(255, 255, 255, 0.2);
          color: #fff;
        }

        .nav-footer-item {
          display: flex;
          flex-direction: column;
          align-items: center;
          justify-content: center;
          text-align: center;
          color: inherit;
          text-decoration: none;
          font-size: 11px;
          flex: 1;
          height: 100%;
          transition: all 0.3s ease;
          cursor: pointer;
        }

        .nav-footer-item i {
          font-size: 16px;
          margin-bottom: 2px;
          transition: transform 0.2s ease;
        }

        .nav-footer-item:hover {
          color: #FF7A00;
        }

        .nav-footer-item:hover i {
          transform: scale(1.1);
        }

        .nav-footer-item.active {
          font-weight: bold;
        }

        @media (max-width: 500px) {
          #nav-footer {
            font-size: 10px;
          }
          .nav-footer-item i {
            font-size: 14px;
          }
        }
      `;
      document.head.appendChild(style);

      // Load Font Awesome (if not already loaded)
      if (!document.querySelector('link[href*="font-awesome"]')) {
        const faLink = document.createElement("link");
        faLink.rel = "stylesheet";
        faLink.href = "https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css";
        document.head.appendChild(faLink);
      }
    }

    // Apply global theme
    this.container.className = '';
    if (settings.theme === 'dark') {
      this.container.classList.add('theme-dark');
    }
  },
  add(settings = { theme: 'bright', selectedColor: '#FF7A00' }, buttons = []) {
    this.init(settings);

    this.container.innerHTML = ""; // Clear existing items

    buttons.forEach(btn => {
      const link = document.createElement("a");
      link.href = btn.url || "#";
      link.className = "nav-footer-item" + (btn.selected ? " active" : "");

      // Apply button-specific theme if provided, otherwise use global theme
      if (btn.theme) {
        link.classList.add(`theme-${btn.theme}`);
      }

      // Apply selected color: button-specific overrides global settings
      if (btn.selected) {
        link.style.color = btn.selectedColor || settings.selectedColor;
      }

      link.innerHTML = `
        <i class="fas ${btn.icon}"></i>
        <span>${btn.name}</span>
      `;

      if (btn.target) {
        link.setAttribute("target", btn.target);
      }

      link.addEventListener("click", function (e) {
        // If onClick is provided, execute it and ignore url
        if (btn.onClick && typeof btn.onClick === 'function') {
          e.preventDefault(); // Prevent default navigation
          btn.onClick(e); // Call the provided function
        } else if (btn.url && btn.url !== "#") {
          window.location.href = btn.url; // Default navigation
        } else {
          e.preventDefault(); // Prevent default if no url
        }
      });

      this.container.appendChild(link);
    });
  }
};