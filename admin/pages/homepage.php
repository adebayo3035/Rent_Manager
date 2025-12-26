<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>KaraKata Pro - Property & Rent Management Solution</title>

    <!-- Font Awesome -->
    <script src="https://kit.fontawesome.com/7cab3097e7.js" crossorigin="anonymous"></script>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">

    <!-- AOS Animation Library -->
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">

    <link rel="stylesheet" href="../css/homepage.css" />
</head>
<body>
    <?php include "navbar.php" ?>

    <!-- MODERN HERO SECTION -->
    <section class="modern-hero">
        <div class="hero-container">
            <div class="hero-content" data-aos="fade-up" data-aos-duration="1000">
                <div class="hero-badge">
                    <span><i class="fas fa-star"></i> Trusted by 500+ Properties</span>
                </div>
                <h1 class="hero-title">
                    <span class="gradient-text">Simplify Property Management</span>
                    <br>with Smart Automation
                </h1>
                <p class="hero-subtitle">
                    All-in-one platform for landlords, property managers, and tenants. 
                    Streamline rent collection, tenant management, and property oversight in one dashboard.
                </p>
                <div class="hero-cta">
                    <a href="dashboard.php" class="btn-primary">
                        <i class="fas fa-rocket"></i> Get Started Free
                    </a>
                    <a href="#features" class="btn-secondary">
                        <i class="fas fa-play-circle"></i> See How It Works
                    </a>
                </div>
                <div class="hero-stats">
                    <div class="stat">
                        <h3>1,200+</h3>
                        <p>Properties Managed</p>
                    </div>
                    <div class="stat">
                        <h3>₦4.8B+</h3>
                        <p>Rent Processed</p>
                    </div>
                    <div class="stat">
                        <h3>98.7%</h3>
                        <p>Client Satisfaction</p>
                    </div>
                </div>
            </div>
            <div class="hero-visual" data-aos="fade-left" data-aos-duration="1200">
                <div class="dashboard-preview">
                    <div class="dashboard-header">
                        <div class="dashboard-nav">
                            <span class="nav-dot" style="background: #FF5F57"></span>
                            <span class="nav-dot" style="background: #FFBD2E"></span>
                            <span class="nav-dot" style="background: #27C93F"></span>
                        </div>
                    </div>
                    <div class="dashboard-content">
                        <div class="metric-card">
                            <i class="fas fa-chart-line"></i>
                            <h4>Revenue Overview</h4>
                            <p>₦2.4M This Month</p>
                        </div>
                        <div class="metric-card">
                            <i class="fas fa-home"></i>
                            <h4>Active Properties</h4>
                            <p>24 Properties</p>
                        </div>
                        <div class="tenant-list">
                            <h4><i class="fas fa-users"></i> Recent Tenants</h4>
                            <div class="tenant-item">
                                <span>John Doe</span>
                                <span class="status paid">Paid</span>
                            </div>
                            <div class="tenant-item">
                                <span>Jane Smith</span>
                                <span class="status pending">Pending</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Scroll Indicator -->
        <div class="scroll-indicator">
            <i class="fas fa-chevron-down"></i>
        </div>
    </section>

    <!-- TRUSTED BY SECTION -->
    <section class="trusted-by">
        <p>Trusted by leading property managers</p>
        <div class="logos">
            <span>Prime Properties</span>
            <span>Elite Estates</span>
            <span>Urban Living</span>
            <span>Smart Spaces</span>
            <span>Heritage Homes</span>
        </div>
    </section>

    <!-- KEY FEATURES -->
    <section class="key-features" id="features">
        <div class="section-header" data-aos="fade-up">
            <h2 class="section-title">Everything You Need in One Platform</h2>
            <p class="section-subtitle">Powerful tools designed specifically for property management</p>
        </div>
        
        <div class="features-grid">
            <div class="feature-card" data-aos="fade-up" data-aos-delay="100">
                <div class="feature-icon">
                    <i class="fas fa-money-check-alt"></i>
                </div>
                <h3>Smart Rent Collection</h3>
                <p>Automated rent collection with multiple payment options, reminders, and late fee calculations.</p>
                <a href="rent_payments.php" class="feature-link">
                    Explore <i class="fas fa-arrow-right"></i>
                </a>
            </div>
            
            <div class="feature-card" data-aos="fade-up" data-aos-delay="200">
                <div class="feature-icon">
                    <i class="fas fa-user-shield"></i>
                </div>
                <h3>Tenant Management</h3>
                <p>Complete tenant profiles, communication tools, and automated onboarding processes.</p>
                <a href="tenant.php" class="feature-link">
                    Explore <i class="fas fa-arrow-right"></i>
                </a>
            </div>
            
            <div class="feature-card" data-aos="fade-up" data-aos-delay="300">
                <div class="feature-icon">
                    <i class="fas fa-chart-pie"></i>
                </div>
                <h3>Advanced Analytics</h3>
                <p>Real-time insights into occupancy rates, revenue trends, and property performance.</p>
                <a href="reports.php" class="feature-link">
                    Explore <i class="fas fa-arrow-right"></i>
                </a>
            </div>
            
            <div class="feature-card" data-aos="fade-up" data-aos-delay="400">
                <div class="feature-icon">
                    <i class="fas fa-file-contract"></i>
                </div>
                <h3>Document Management</h3>
                <p>Digital lease agreements, maintenance requests, and automated document storage.</p>
                <a href="property.php" class="feature-link">
                    Explore <i class="fas fa-arrow-right"></i>
                </a>
            </div>
            
            <div class="feature-card" data-aos="fade-up" data-aos-delay="500">
                <div class="feature-icon">
                    <i class="fas fa-mobile-alt"></i>
                </div>
                <h3>Mobile App</h3>
                <p>Access your property portfolio anytime, anywhere with our iOS and Android apps.</p>
                <a href="#" class="feature-link">
                    Explore <i class="fas fa-arrow-right"></i>
                </a>
            </div>
            
            <div class="feature-card" data-aos="fade-up" data-aos-delay="600">
                <div class="feature-icon">
                    <i class="fas fa-headset"></i>
                </div>
                <h3>24/7 Support</h3>
                <p>Dedicated support team and comprehensive resources to help you succeed.</p>
                <a href="#contact" class="feature-link">
                    Explore <i class="fas fa-arrow-right"></i>
                </a>
            </div>
        </div>
    </section>

    <!-- PROPERTY SHOWCASE -->
    <section class="property-showcase">
        <div class="showcase-container">
            <div class="showcase-content" data-aos="fade-right">
                <h2>Showcase Your Properties</h2>
                <p>Beautiful property listings with high-quality images, virtual tours, and detailed descriptions to attract the right tenants.</p>
                <div class="showcase-features">
                    <div class="showcase-feature">
                        <i class="fas fa-check-circle"></i>
                        <span>High-quality image galleries</span>
                    </div>
                    <div class="showcase-feature">
                        <i class="fas fa-check-circle"></i>
                        <span>Virtual tour integration</span>
                    </div>
                    <div class="showcase-feature">
                        <i class="fas fa-check-circle"></i>
                        <span>Automated availability updates</span>
                    </div>
                    <div class="showcase-feature">
                        <i class="fas fa-check-circle"></i>
                        <span>Lead tracking and management</span>
                    </div>
                </div>
                <a href="property.php" class="btn-primary">
                    <i class="fas fa-eye"></i> Browse Properties
                </a>
            </div>
            <div class="showcase-visual" data-aos="fade-left">
                <div class="property-card">
                    <div class="property-badge">Featured</div>
                    <div class="property-image">
                        <!-- Image would go here -->
                        <div class="image-placeholder"></div>
                    </div>
                    <div class="property-details">
                        <h3>Luxury 3-Bedroom Apartment</h3>
                        <p class="property-location"><i class="fas fa-map-marker-alt"></i> Lekki Phase 1, Lagos</p>
                        <div class="property-specs">
                            <span><i class="fas fa-bed"></i> 3 Bedrooms</span>
                            <span><i class="fas fa-bath"></i> 3 Bathrooms</span>
                            <span><i class="fas fa-car"></i> 2 Parking</span>
                        </div>
                        <div class="property-price">
                            <strong>₦4,500,000/year</strong>
                            <span class="property-status available">Available</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- TESTIMONIALS CAROUSEL -->
    <section class="testimonials-carousel">
        <div class="section-header" data-aos="fade-up">
            <h2 class="section-title">Trusted by Property Professionals</h2>
            <p class="section-subtitle">See what our clients say about their experience</p>
        </div>
        
        <div class="testimonial-slider" data-aos="fade-up">
            <div class="testimonial-card">
                <div class="testimonial-rating">
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                </div>
                <p class="testimonial-text">
                    "KaraKata Pro transformed how we manage our 50+ properties. The automation features saved us 20 hours per week!"
                </p>
                <div class="testimonial-author">
                    <div class="author-avatar">
                        <i class="fas fa-user-circle"></i>
                    </div>
                    <div class="author-info">
                        <h4>David Johnson</h4>
                        <p>Property Manager, Elite Estates</p>
                    </div>
                </div>
            </div>
            
            <div class="testimonial-card">
                <div class="testimonial-rating">
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star-half-alt"></i>
                </div>
                <p class="testimonial-text">
                    "As a landlord with multiple tenants, the automated reminders and payment tracking have been game-changing."
                </p>
                <div class="testimonial-author">
                    <div class="author-avatar">
                        <i class="fas fa-user-circle"></i>
                    </div>
                    <div class="author-info">
                        <h4>Sarah Williams</h4>
                        <p>Property Owner, 12 Units</p>
                    </div>
                </div>
            </div>
            
            <div class="testimonial-card">
                <div class="testimonial-rating">
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                </div>
                <p class="testimonial-text">
                    "The reporting features give us insights we never had before. Highly recommended for any serious property business."
                </p>
                <div class="testimonial-author">
                    <div class="author-avatar">
                        <i class="fas fa-user-circle"></i>
                    </div>
                    <div class="author-info">
                        <h4>Michael Chen</h4>
                        <p>CEO, Urban Living Group</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA SECTION -->
    <section class="cta-section">
        <div class="cta-container" data-aos="zoom-in">
            <h2>Ready to Transform Your Property Management?</h2>
            <p>Join thousands of property professionals who trust KaraKata Pro</p>
            <div class="cta-buttons">
                <a href="dashboard.php" class="btn-primary btn-large">
                    <i class="fas fa-play-circle"></i> Start Free Trial
                </a>
                <a href="#contact" class="btn-secondary btn-large">
                    <i class="fas fa-calendar-alt"></i> Schedule Demo
                </a>
            </div>
            <div class="cta-features">
                <span><i class="fas fa-check"></i> No credit card required</span>
                <span><i class="fas fa-check"></i> 14-day free trial</span>
                <span><i class="fas fa-check"></i> Cancel anytime</span>
            </div>
        </div>
    </section>

    <!-- FOOTER -->
    <footer class="modern-footer">
        <div class="footer-container">
            <div class="footer-brand">
                <div class="footer-logo">
                    <i class="fas fa-building"></i>
                    <span>KaraKata <strong>Pro</strong></span>
                </div>
                <p class="footer-tagline">Professional Property Management Solutions</p>
                <div class="footer-social">
                    <a href="#"><i class="fab fa-twitter"></i></a>
                    <a href="#"><i class="fab fa-facebook-f"></i></a>
                    <a href="#"><i class="fab fa-linkedin-in"></i></a>
                    <a href="#"><i class="fab fa-instagram"></i></a>
                </div>
            </div>
            
            <div class="footer-links">
                <div class="footer-column">
                    <h4>Product</h4>
                    <a href="dashboard.php">Dashboard</a>
                    <a href="property.php">Properties</a>
                    <a href="tenant.php">Tenant Management</a>
                    <a href="reports.php">Reporting</a>
                    <a href="rent_payments.php">Rent Collection</a>
                </div>
                
                <div class="footer-column">
                    <h4>Resources</h4>
                    <a href="#">Help Center</a>
                    <a href="#">Blog</a>
                    <a href="#">Video Tutorials</a>
                    <a href="#">API Documentation</a>
                    <a href="#">Community</a>
                </div>
                
                <div class="footer-column">
                    <h4>Company</h4>
                    <a href="#about">About Us</a>
                    <a href="#contact">Contact</a>
                    <a href="#">Careers</a>
                    <a href="#">Privacy Policy</a>
                    <a href="#">Terms of Service</a>
                </div>
                
                <div class="footer-column">
                    <h4>Contact</h4>
                    <p><i class="fas fa-phone"></i> +234-810-327-3279</p>
                    <p><i class="fas fa-envelope"></i> support@karakata.com</p>
                    <p><i class="fas fa-map-marker-alt"></i> Lagos, Nigeria</p>
                </div>
            </div>
        </div>
        
        <div class="footer-bottom">
            <p>&copy; 2025 KaraKata Pro. All rights reserved.</p>
            <div class="footer-legal">
                <a href="#">Privacy Policy</a>
                <a href="#">Terms of Service</a>
                <a href="#">Cookie Policy</a>
            </div>
        </div>
    </footer>

    <!-- AOS Animation Script -->
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script>
        AOS.init({
            duration: 1000,
            once: true,
            offset: 100
        });
    </script>
    
    <script src="../scripts/homepage.js"></script>
</body>
</html>