const profile = {
  config: {
    position: "center",
    auto_open: true,
    redirect_url: "profile.html"
  },
  
  init: function(options) {
    // Merge config with options
    this.config = {...this.config, ...options};
    
    // Create button element
    this.button = document.createElement('div');
    this.button.id = 'profile-floating-btn';
    this.button.className = 'profile-closed';
    
    // Add Font Awesome icon
    const icon = document.createElement('i');
    icon.className = 'fas fa-user';
    
    // Add text span
    this.text = document.createElement('span');
    this.text.className = 'profile-btn-text';
    this.text.textContent = 'Profile';
    
    // Append icon and text to button
    this.button.appendChild(icon);
    this.button.appendChild(this.text);
    
    // Add click handler
    this.button.addEventListener('click', () => {
      window.location.href = this.config.redirect_url;
    });
    
    // Append button to body
    document.body.appendChild(this.button);
    
    // Add styles
    this.addStyles();
    
    // Set position
    this.setPosition(this.config.position);
    
    // Auto open if configured
    if (this.config.auto_open) {
      setTimeout(() => this.open(), 500);
    }
  },
  
  addStyles: function() {
    const style = document.createElement('style');
    style.textContent = `
      /* Add Font Awesome CSS */
      @import url('https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css');
      
      #profile-floating-btn {
        position: fixed;
        bottom: 20px;
        display: flex;
        align-items: center;
        padding: 12px 15px;
        padding-right: 20px;
        background: rgba(0, 0, 0, 0.2);
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
        border-radius: 50px;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        color: #fff;
        font-family: "Poppins", sans-serif;
        font-weight: 700;
        border: 1px solid rgba(255, 255, 255, 0.3);
        overflow: hidden;
        width: 50px;
        height: 50px;
        transition: all 0.3s ease;
        z-index: 1000;
        cursor: pointer;
      }
      
      #profile-floating-btn.profile-open {
        width: 130px;
        background: rgba(0, 0, 0, 0.3);
      }
      
      #profile-floating-btn i {
        font-size: 20px;
        margin-right: 10px;
        min-width: 20px;
      }
      
      .profile-btn-text {
        white-space: nowrap;
        opacity: 0;
        transition: opacity 0.2s ease 0.1s;
      }
      
      #profile-floating-btn.profile-open .profile-btn-text {
        opacity: 1;
      }
      
      /* Position classes */
      .profile-position-left {
        left: 20px;
        right: auto;
      }
      
      .profile-position-center {
        left: 50%;
        transform: translateX(-50%);
      }
      
      .profile-position-right {
        right: 20px;
        left: auto;
      }
    `;
    
    document.head.appendChild(style);
  },
  
  setPosition: function(position) {
    // Remove all position classes
    this.button.classList.remove(
      'profile-position-left',
      'profile-position-center',
      'profile-position-right'
    );
    
    // Add new position class
    const validPositions = ['left', 'center', 'right'];
    const selectedPosition = validPositions.includes(position) ? position : 'center';
    this.button.classList.add(`profile-position-${selectedPosition}`);
  },
  
  open: function() {
    this.button.classList.add('profile-open');
  },
  
  close: function() {
    this.button.classList.remove('profile-open');
  },
  
  toggle: function() {
    this.button.classList.toggle('profile-open');
  }
};

// Auto-init if script is included
if (document.readyState === 'complete') {
  profile.init();
} else {
  window.addEventListener('load', () => profile.init());
}