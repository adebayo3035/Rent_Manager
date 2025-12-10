/**
 * Reusable Form Validator with Error Message Label
 * Now includes file validation with size, type, and emptiness checks
 *
 * Usage:
 * initFormValidation(formId, submitBtnId, messageLabelId, options)
 *
 * Add class="validate" and data-type="text|email|phone|number|select|password|file"
 * For file inputs, also add data-file-types and data-max-size attributes
 *
 * Example:
 * <input type="file" class="validate" data-type="file"
 *        data-file-types="jpg,jpeg,png,gif"
 *        data-max-size="5"
 *        accept=".jpg,.jpeg,.png,.gif">
 */
function initFormValidation(formId, submitBtnId, messageLabelId, options = {}) {
  const form = document.getElementById(formId);
  const submitBtn = document.getElementById(submitBtnId);
  const messageLabel = document.getElementById(messageLabelId);

  if (!form || !submitBtn || !messageLabel) return;

  const fields = form.querySelectorAll(".validate");

  // Default options
  const config = {
    showFieldErrors: true, // Show individual field errors
    maxFileSizeMB: 5, // Default max file size in MB
    allowedFileTypes: ["jpg", "jpeg", "png", "gif", "pdf"], // Default allowed types
    requiredFile: true, // File is required by default
    ...options,
  };

  /** File validation rules */
  function validateFileField(field) {
    const files = field.files;
    const required = field.hasAttribute("required") || config.requiredFile;
    const allowedTypes = field.dataset.fileTypes
      ? field.dataset.fileTypes.split(",").map((t) => t.trim().toLowerCase())
      : config.allowedFileTypes;

    const maxSizeMB = field.dataset.maxSize
      ? parseInt(field.dataset.maxSize)
      : config.maxFileSizeMB;
    const maxSizeBytes = maxSizeMB * 1024 * 1024;

    // Check if file is required but not provided
    if (required && (!files || files.length === 0)) {
      return "Please select a file to upload.";
    }

    // If not required and no file selected, it's valid
    if (!required && (!files || files.length === 0)) {
      return "";
    }

    // File exists, validate it
    const file = files[0];

    // Validate file size
    if (file.size > maxSizeBytes) {
      return `File size must be less than ${maxSizeMB}MB.`;
    }

    // Validate file type
    const fileExtension = file.name.split(".").pop().toLowerCase();
    if (!allowedTypes.includes(fileExtension)) {
      return `File type must be one of: ${allowedTypes.join(", ")}.`;
    }

    // Additional validation for image files
    if (["jpg", "jpeg", "png", "gif"].includes(fileExtension)) {
      // Check if it's actually an image (not just extension)
      if (!file.type.startsWith("image/")) {
        return "Please upload a valid image file.";
      }
    }

    // Validate file name length
    if (file.name.length > 255) {
      return "File name is too long.";
    }

    return ""; // All validations passed
  }

  /** Validation rules per field */
  function validateField(field) {
    const type = field.dataset.type || field.type;
    const value = field.value.trim();

    // Handle file inputs separately
    if (type === "file" || field.type === "file") {
      return validateFileField(field);
    }

    switch (type) {
      case "text":
      case "textarea":
        return value.length > 0 ? "" : "This field cannot be empty.";

      case "email":
        if (value.length === 0) return "Email cannot be empty.";
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)
          ? ""
          : "Please enter a valid email address.";

      case "phone":
      case "tel":
        if (value.length === 0) return "Phone number cannot be empty.";
        return /^[0-9]{11}$/.test(value)
          ? ""
          : "Phone number must be 11 digits.";

      case "number":
        if (value.length === 0) return "This field cannot be empty.";
        return /^[0-9]+$/.test(value) ? "" : "Only numbers are allowed.";

      case "select":
        return value !== "" && value !== "0" ? "" : "Please select an option.";

      case "password":
        if (value.length === 0) return "Password cannot be empty.";
        return value.length >= 6
          ? ""
          : "Password must be at least 6 characters.";

      case "date":
        if (value.length === 0) return "Date cannot be empty.";
        const date = new Date(value);
        return !isNaN(date.getTime()) ? "" : "Please enter a valid date.";

      default:
        return value.length > 0 ? "" : "This field cannot be empty.";
    }
  }

  /** Display field-specific error */
  function showFieldError(field, message) {
    // Remove existing error
    hideFieldError(field);

    // Create error element
    const errorDiv = document.createElement("div");
    errorDiv.className = "field-error";
    errorDiv.style.color = "red";
    errorDiv.style.fontSize = "0.875em";
    errorDiv.style.marginTop = "4px";
    errorDiv.innerHTML = message;

    // Insert after field
    field.parentNode.insertBefore(errorDiv, field.nextSibling);

    // Add error class to field
    field.classList.add("error");
  }

  /** Hide field-specific error */
  function hideFieldError(field) {
    const existingError = field.parentNode.querySelector(".field-error");
    if (existingError) {
      existingError.remove();
    }
    field.classList.remove("error");
  }

  /** Validate single field and show error */
  function validateAndShowFieldError(field) {
    const errorMsg = validateField(field);

    if (errorMsg && config.showFieldErrors) {
      showFieldError(field, errorMsg);
    } else {
      hideFieldError(field);
    }

    return errorMsg;
  }

  /** Overall validation check */
  function checkAllFields() {
    let allValid = true;
    let firstError = "";

    fields.forEach((field) => {
      const errorMsg = validateAndShowFieldError(field);

      if (errorMsg) {
        allValid = false;
        if (!firstError) {
          firstError = errorMsg;
        }
      }
    });

    if (!allValid) {
      messageLabel.innerHTML = `<span style="color:red;">${firstError}</span>`;
      submitBtn.style.display = "none";
      submitBtn.disabled = true;
    } else {
      messageLabel.innerHTML = "";
      submitBtn.style.display = "block";
      submitBtn.disabled = false;
    }

    return allValid;
  }

  /** Enhanced validation for form submission */
  function validateForSubmission() {
    return checkAllFields();
  }

  // Listen to changes on fields
  fields.forEach((field) => {
    field.addEventListener("input", () => {
      validateAndShowFieldError(field);
      checkAllFields();
    });

    field.addEventListener("change", () => {
      validateAndShowFieldError(field);
      checkAllFields();
    });

    // Special handling for file inputs to show file info
    if (field.type === "file") {
      field.addEventListener("change", function (e) {
        if (this.files && this.files[0]) {
          const file = this.files[0];
          const fileInfo = document.createElement("div");
          fileInfo.className = "file-info";
          fileInfo.style.fontSize = "0.875em";
          fileInfo.style.color = "#666";
          fileInfo.style.marginTop = "4px";
          fileInfo.innerHTML = `
                        Selected: ${file.name} 
                        (${(file.size / 1024 / 1024).toFixed(2)}MB)
                    `;

          // Remove existing file info
          const existingInfo = this.parentNode.querySelector(".file-info");
          if (existingInfo) existingInfo.remove();

          this.parentNode.appendChild(fileInfo);
        }
      });
    }
  });

  // Validate immediately on load
  checkAllFields();

  // Return validation function for manual validation
  return {
    validate: checkAllFields,
    validateForSubmission,
    isValid: () => checkAllFields(),
  };
}

/**
 * Helper function to validate file before upload
 * Can be called independently
 */
function validateFile(file, options = {}) {
  const config = {
    maxSizeMB: 5,
    allowedTypes: ["jpg", "jpeg", "png", "gif", "pdf"],
    required: true,
    ...options,
  };

  const maxSizeBytes = config.maxSizeMB * 1024 * 1024;

  if (config.required && !file) {
    return { valid: false, error: "No file selected." };
  }

  if (!file && !config.required) {
    return { valid: true, error: "" };
  }

  // Validate size
  if (file.size > maxSizeBytes) {
    return {
      valid: false,
      error: `File size must be less than ${config.maxSizeMB}MB.`,
    };
  }

  // Validate type
  const fileExtension = file.name.split(".").pop().toLowerCase();
  if (!config.allowedTypes.includes(fileExtension)) {
    return {
      valid: false,
      error: `File type must be one of: ${config.allowedTypes.join(", ")}.`,
    };
  }

  // Additional image validation
  if (["jpg", "jpeg", "png", "gif"].includes(fileExtension)) {
    if (!file.type.startsWith("image/")) {
      return { valid: false, error: "Please upload a valid image file." };
    }
  }

  return { valid: true, error: "" };
}

/**
 * Initialize form validation with file preview
 * For image files only
 */
function initFormValidationWithPreview(
  formId,
  submitBtnId,
  messageLabelId,
  previewId,
  options = {}
) {
  const previewElement = document.getElementById(previewId);

  // Initialize base validation
  const validator = initFormValidation(
    formId,
    submitBtnId,
    messageLabelId,
    options
  );

  if (previewElement) {
    const form = document.getElementById(formId);
    const fileInput = form.querySelector('input[type="file"]');

    if (fileInput) {
      fileInput.addEventListener("change", function (e) {
        if (this.files && this.files[0]) {
          const file = this.files[0];

          // Only preview images
          if (file.type.startsWith("image/")) {
            const reader = new FileReader();

            reader.onload = function (e) {
              previewElement.innerHTML = `
                                <img src="${e.target.result}" 
                                     style="max-width: 200px; max-height: 200px; margin-top: 10px;">
                            `;
            };

            reader.readAsDataURL(file);
          } else {
            previewElement.innerHTML = `
                            <div style="margin-top: 10px; color: #666;">
                                File selected: ${file.name}
                            </div>
                        `;
          }
        } else {
          previewElement.innerHTML = "";
        }
      });
    }
  }

  return validator;
}
