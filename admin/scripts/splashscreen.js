document.addEventListener("DOMContentLoaded", function (url) {
    const loadSplashScreen = (url)=>{
        setTimeout(function () {
            // Redirect to the login page after 30 seconds
            window.location.href = url;
        }, 10000); // 30 seconds in milliseconds
    }
    loadSplashScreen("homepage.php");
});
