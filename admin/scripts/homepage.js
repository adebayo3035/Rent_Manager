$(document).ready(function () {
    $(document).ready(function () {
        $('.testimonial-slider').slick({
            dots: true,
            infinite: true,
            speed: 500,
            slidesToShow: 2,
            slidesToScroll: 1,
            autoplay: true,
            autoplaySpeed: 3000,
            arrows: false
        });
    });
});

document.addEventListener('DOMContentLoaded', (event) => {
    event.preventDefault();
    fetch('../backend/tenants/fetch_random_customers.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        }
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const customerNames = document.querySelectorAll('.customerName');
                customerNames.forEach((element, index) => {
                    const customer = data.customer_names[index % data.customer_names.length];
                    element.textContent = `- ${customer.firstname} ${customer.lastname}`;
                });
            } else {
                console.error('Failed to fetch Customer Information:', data.message);
            }
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

})

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