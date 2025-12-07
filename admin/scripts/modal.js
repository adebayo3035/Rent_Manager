class Modal {
  constructor() {
    this.modal = document.getElementById('appModal');
    this.modalContainer = this.modal.querySelector('.modal-container');
    this.modalHeader = this.modal.querySelector('.modal-header');
    this.modalIcon = this.modal.querySelector('.modal-icon');
    this.modalTitle = this.modal.querySelector('.modal-title');
    this.modalMessage = this.modal.querySelector('.modal-message');
    this.modalFooter = this.modal.querySelector('.modal-footer');
    this.primaryBtn = this.modal.querySelector('.modal-btn-primary');
    this.secondaryBtn = this.modal.querySelector('.modal-btn-secondary');
    this.closeBtn = this.modal.querySelector('.modal-close');
    
    this.setupEvents();
  }
  
  setupEvents() {
    this.closeBtn.addEventListener('click', () => this.hide());
    this.modal.querySelector('.modal-overlay').addEventListener('click', () => this.hide());
    this.secondaryBtn.addEventListener('click', () => this.hide());
  }
  
  show({
    title = '',
    message = '',
    type = 'info',
    icon = '',
    showSecondaryBtn = true,
    secondaryBtnText = 'Cancel',
    primaryBtnText = 'OK',
    onPrimaryClick = null,
    onSecondaryClick = null,
    hideOnClickOutside = true
  } = {}) {
    // Set modal type and icon
    this.modalContainer.className = 'modal-container';
    this.modalContainer.classList.add(`modal-${type}`);
    
    // Set icon (using Unicode or Font Awesome)
    this.modalIcon.innerHTML = icon || this.getDefaultIcon(type);
    
    // Set content
    this.modalTitle.textContent = title;
    this.modalMessage.innerHTML = message;
    
    // Configure buttons
    this.primaryBtn.textContent = primaryBtnText;
    this.secondaryBtn.textContent = secondaryBtnText;
    
    if (showSecondaryBtn) {
      this.secondaryBtn.style.display = 'inline-block';
    } else {
      this.secondaryBtn.style.display = 'none';
    }
    
    // Set button handlers
    this.primaryBtn.onclick = () => {
      if (onPrimaryClick) onPrimaryClick();
      this.hide();
    };
    
    this.secondaryBtn.onclick = () => {
      if (onSecondaryClick) onSecondaryClick();
      this.hide();
    };
    
    // Show modal
    this.modal.classList.add('active');
    document.body.style.overflow = 'hidden';
  }
  
  hide() {
    this.modal.classList.remove('active');
    document.body.style.overflow = '';
  }
  
  getDefaultIcon(type) {
    const icons = {
      error: '✕',
      success: '✓',
      warning: '⚠',
      info: 'i'
    };
    return icons[type] || 'i';
  }
}

// Initialize modal
const appModal = new Modal();

// Export for use in other files
window.showModal = (options) => appModal.show(options);
window.hideModal = () => appModal.hide();