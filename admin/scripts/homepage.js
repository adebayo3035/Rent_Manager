// $(document).ready(function () {
//     $(document).ready(function () {
//         $('.testimonial-slider').slick({
//             dots: true,
//             infinite: true,
//             speed: 500,
//             slidesToShow: 2,
//             slidesToScroll: 1,
//             autoplay: true,
//             autoplaySpeed: 3000,
//             arrows: false
//         });
//     });
// });

// document.addEventListener('DOMContentLoaded', (event) => {
//     event.preventDefault();
//    event.preventDefault();



// })

function toggleReadMore() {
    const aboutText = document.getElementById('aboutText');
    const readMoreLink = document.getElementById('readMoreLink');

    if (readMoreLink.innerText === "Read More") {
        aboutText.style.height = "auto";
        readMoreLink.innerText = "Read Less";
    } else {
        aboutText.style.height = "200px"; // Same height as defined in the CSS
        readMoreLink.innerText = "Read More";
    }
}

// homepage.js
document.addEventListener('DOMContentLoaded', function(event) {
    console.log('KaraKata Pro Homepage loaded');
    event.preventDefault();
    fetch('../backend/clients/fetch_random_clients.php', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json'
  }
})
.then(response => response.json())
.then(data => {
  if (
    !data.success ||
    !data.data ||
    !Array.isArray(data.data.customer_names)
  ) {
    console.error('Invalid API response:', data);
    return;
  }

  const customerNames = document.querySelectorAll('.customerName');
  const customers = data.data.customer_names;

  customerNames.forEach((element, index) => {
    const customer = customers[index % customers.length];
    element.textContent = `- ${customer.firstname} ${customer.lastname}`;
  });
})
.catch(error => {
  console.error('Error fetching Customers Info:', error);
});



        // fetch Ongoing Promos
        fetch('../backend/get_promo.php')
        .then(response => response.json())
        .then(data => {
            const specialsSection = document.getElementById('specials');
            
            if (data.promos.ongoing && data.promos.ongoing.length > 0) {
                data.promos.ongoing.forEach(promo => {
                    const promoElement = document.createElement('div');
                    promoElement.classList.add('promo');
                    promoElement.innerHTML = `
                        <h3>${promo.promo_name}</h3>
                        <p>${promo.promo_description}</p>
                        <p>Code: <strong>${promo.promo_code}</strong></p>
                        <p>Discount: ${promo.discount_value}%</p>
                        ${promo.max_discount ? `<p>Max Discount: ${promo.max_discount}</p>` : ''}
                    `;
                    specialsSection.appendChild(promoElement);
                });
            } else {
                specialsSection.innerHTML += '<p>No ongoing promotions at the moment.</p>';
            }
        })
        .catch(error => {
            console.error('Error fetching promotions:', error);
        });

    
    // Animate stats counter
    function animateStats() {
        const stats = document.querySelectorAll('.stat h3');
        if (!stats.length) return;
        
        stats.forEach(stat => {
            const target = parseInt(stat.textContent.replace(/[^0-9]/g, ''));
            const suffix = stat.textContent.replace(/[0-9]/g, '').replace('+', '').replace('.', '');
            let current = 0;
            const increment = target / 100;
            const timer = setInterval(() => {
                current += increment;
                if (current >= target) {
                    current = target;
                    clearInterval(timer);
                }
                stat.textContent = Math.floor(current).toLocaleString() + suffix;
            }, 20);
        });
    }
    
    // Initialize stats animation when in viewport
    const observerOptions = {
        root: null,
        rootMargin: '0px',
        threshold: 0.3
    };
    
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                animateStats();
                observer.unobserve(entry.target);
            }
        });
    }, observerOptions);
    
    const statsSection = document.querySelector('.modern-hero');
    if (statsSection) {
        observer.observe(statsSection);
    }
    
    // Smooth scrolling for anchor links
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function(e) {
            const href = this.getAttribute('href');
            if (href === '#') return;
            
            e.preventDefault();
            const targetId = href.substring(1);
            const targetElement = document.getElementById(targetId);
            
            if (targetElement) {
                window.scrollTo({
                    top: targetElement.offsetTop - 80,
                    behavior: 'smooth'
                });
            }
        });
    });
    
    // Add hover effects to feature cards
    const featureCards = document.querySelectorAll('.feature-card');
    featureCards.forEach(card => {
        card.addEventListener('mouseenter', () => {
            card.style.transform = 'translateY(-10px)';
        });
        
        card.addEventListener('mouseleave', () => {
            card.style.transform = 'translateY(0)';
        });
    });
    
    // Testimonial slider functionality
    function initTestimonialSlider() {
        const slider = document.querySelector('.testimonial-slider');
        if (!slider) return;
        
        let currentIndex = 0;
        const cards = slider.querySelectorAll('.testimonial-card');
        const totalCards = cards.length;
        
        // Create navigation dots if needed
        if (totalCards > 1) {
            const dotsContainer = document.createElement('div');
            dotsContainer.className = 'testimonial-dots';
            
            for (let i = 0; i < totalCards; i++) {
                const dot = document.createElement('button');
                dot.className = 'testimonial-dot';
                if (i === 0) dot.classList.add('active');
                dot.addEventListener('click', () => {
                    showTestimonial(i);
                });
                dotsContainer.appendChild(dot);
            }
            
            slider.parentNode.appendChild(dotsContainer);
            
            // Auto-rotate testimonials
            setInterval(() => {
                currentIndex = (currentIndex + 1) % totalCards;
                showTestimonial(currentIndex);
            }, 5000);
        }
        
        function showTestimonial(index) {
            cards.forEach(card => {
                card.style.display = 'none';
            });
            
            cards[index].style.display = 'block';
            currentIndex = index;
            
            // Update dots
            const dots = document.querySelectorAll('.testimonial-dot');
            dots.forEach((dot, i) => {
                dot.classList.toggle('active', i === index);
            });
        }
    }
    
    initTestimonialSlider();
    
    // Add scroll progress indicator
    function initScrollIndicator() {
        const progressBar = document.createElement('div');
        progressBar.className = 'scroll-progress';
        progressBar.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            width: 0%;
            height: 3px;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            z-index: 1001;
            transition: width 0.1s ease;
        `;
        document.body.appendChild(progressBar);
        
        window.addEventListener('scroll', () => {
            const winScroll = document.body.scrollTop || document.documentElement.scrollTop;
            const height = document.documentElement.scrollHeight - document.documentElement.clientHeight;
            const scrolled = (winScroll / height) * 100;
            progressBar.style.width = scrolled + '%';
        });
    }
    
    initScrollIndicator();
    
    // Add hover effect to property cards
    const propertyCards = document.querySelectorAll('.property-card');
    propertyCards.forEach(card => {
        card.addEventListener('mouseenter', () => {
            card.style.transform = 'translateY(-10px)';
        });
        
        card.addEventListener('mouseleave', () => {
            card.style.transform = 'translateY(0)';
        });
    });
    
    // Email validation for newsletter (if added)
    const newsletterForm = document.querySelector('.newsletter-form');
    if (newsletterForm) {
        newsletterForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const email = this.querySelector('input[type="email"]').value;
            
            if (!validateEmail(email)) {
                alert('Please enter a valid email address');
                return;
            }
            
            // Here you would typically send the email to your backend
            alert('Thank you for subscribing!');
            this.reset();
        });
    }
    
    function validateEmail(email) {
        const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return re.test(email);
    }
    
    // Add fade-in animation for elements on scroll
    const fadeElements = document.querySelectorAll('.feature-card, .testimonial-card, .property-card');
    const fadeObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
            }
        });
    }, {
        threshold: 0.1
    });
    
    fadeElements.forEach(el => {
        el.style.opacity = '0';
        el.style.transform = 'translateY(20px)';
        el.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
        fadeObserver.observe(el);
    });
});