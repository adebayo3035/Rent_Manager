<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>RentEase - Property & Rent Management</title>

    <!-- Font Awesome -->
    <script src="https://kit.fontawesome.com/7cab3097e7.js" crossorigin="anonymous"></script>

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;600;700&family=Roboto&display=swap" rel="stylesheet" />

    <!-- Slick Slider -->
    <link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/slick-carousel@1.8.1/slick/slick.css" />
    <link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/slick-carousel@1.8.1/slick/slick-theme.css" />
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/slick-carousel@1.8.1/slick/slick.min.js"></script>

    <link rel="stylesheet" href="../css/homepage.css" />
</head>
<body>
    <?php include "navbar.php" ?>

    <!-- HERO SECTION -->
    <section class="hero">
        <div class="hero-content">
            <h2>Property & Rent Management Made Easy</h2>
            <p>Find, manage, and track your properties all in one place.</p>
            <a href="property.php" class="btn">View Properties</a>
        </div>
        <!-- <img src="../../images/property-hero.jpg" alt="Properties" class="hero-image" /> -->
    </section>

    <!-- ABOUT SECTION -->
    <section id="about" class="about">
        <h2>About RentEase</h2>
        <p>
            RentEase is your trusted platform for modern property and rent management. Whether you are a landlord,
            tenant, or property manager, we provide seamless tools to keep everything organized.
            Track rent payments, manage tenants, oversee multiple properties, and enjoy automated reminders — all from one dashboard.
        </p>
    </section>

    <!-- FEATURED PROPERTIES -->
    <section id="menu" class="menu">
        <h2>Featured Properties</h2>
        <div class="menu-items">
            <div class="menu-item">
                <img src="../../images/room.jpg" alt="Property 1" />
                <h3>3-Bedroom Apartment</h3>
                <p>Located in a serene environment with 24/7 security and water supply.</p>
            </div>
            <div class="menu-item">
                <img src="../../images/luxury.jpg" alt="Property 2" />
                <h3>Luxury Duplex</h3>
                <p>Spacious and modern design suitable for families who love comfort.</p>
            </div>
            <div class="menu-item">
                <img src="../../images/3-bed.jpg" alt="Property 3" />
                <h3>Serviced Mini-Flat</h3>
                <p>Affordable and well-maintained mini-flat perfect for young professionals.</p>
            </div>
        </div>
    </section>

    <!-- SPECIAL OFFERS -->
    <section id="specials" class="specials">
        <h2>Latest Offers</h2>
        <p>Check out discounts on selected properties and limited-time rent deals.</p>
    </section>

    <!-- TESTIMONIALS -->
    <section id="testimonials" class="testimonials">
        <h2>What Our Clients Say</h2>
        <div class="testimonial-slider">
            <div class="testimonial">
                <p>"RentEase made managing my tenants stress-free. Great platform!"</p>
                <p class="customerName">- Landlord</p>
            </div>
            <div class="testimonial">
                <p>"Paying rent is now smooth and transparent. Highly recommended!"</p>
                <p class="customerName">- Tenant</p>
            </div>
            <div class="testimonial">
                <p>"The automated reminders and tracking features are incredible."</p>
                <p class="customerName">- Property Manager</p>
            </div>
        </div>
    </section>

    <!-- HOW IT WORKS -->
    <section id="how-it-works" class="how-it-works">
        <h2>How It Works</h2>
        <div class="steps">
            <div class="step">
                <i class="fa fa-home"></i>
                <p>Browse available properties or register your own.</p>
            </div>
            <div class="step">
                <i class="fa fa-user"></i>
                <p>Manage tenants, rent collections, and maintenance records.</p>
            </div>
            <div class="step">
                <i class="fa fa-file-invoice"></i>
                <p>Track rent payments and get automatic reminders.</p>
            </div>
        </div>
    </section>

    <!-- CONTACT SECTION -->
    <section id="contact" class="contact">
        <h2>Contact Us</h2>
        <p>
            Phone: <a href="tel:+2348103273279">+234-810-327-3279</a>
            <br />
            Email: <a href="mailto:adebayoabdulrahmon@gmail.com">adebayoabdulrahmon@gmail.com</a>
        </p>
    </section>

    <footer>
        <p>&copy; 2025 RentEase Property Manager. All rights reserved.</p>
    </footer>

    <script src="../scripts/homepage.js"></script>
</body>
</html>