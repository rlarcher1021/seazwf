// assets/js/main.js

document.addEventListener('DOMContentLoaded', function() {

    // Check if we are on the check-in page (e.g., by checking body class or a specific element)
    if (document.body.classList.contains('checkin-page')) {
        // Inactivity Timeout Reset for Check-in Page
        let inactivityTimer;
        const resetTimeoutDuration = 90 * 1000; // 90 seconds

        function resetCheckinTimer() {
            clearTimeout(inactivityTimer);
            inactivityTimer = setTimeout(() => {
                window.location.href = 'checkin.php'; // Reload page
            }, resetTimeoutDuration);
        }

        // Initial setup and event listeners
        resetCheckinTimer(); // Start timer on load
        document.addEventListener('mousemove', resetCheckinTimer);
        document.addEventListener('keypress', resetCheckinTimer);
        document.addEventListener('click', resetCheckinTimer);
        document.addEventListener('scroll', resetCheckinTimer);
        document.addEventListener('touchstart', resetCheckinTimer);
    }

    // Add other general JS functions here if needed in the future
    // e.g., function setupModals() { ... }

}); // End DOMContentLoaded
 // Inactivity Timeout Reset
        let inactivityTimer;
        const resetTimeoutDuration = 90 * 1000; // 90 seconds in milliseconds

        function resetInactivityTimer() {
            clearTimeout(inactivityTimer);
            inactivityTimer = setTimeout(() => {
                // Reload the page to reset the form to blank state
                window.location.href = 'checkin.php';
            }, resetTimeoutDuration);
        }

        // Events that indicate user activity
        window.onload = resetInactivityTimer;
        document.onmousemove = resetInactivityTimer;
        document.onkeypress = resetInactivityTimer;
        document.onclick = resetInactivityTimer;
        document.onscroll = resetInactivityTimer; // Handle scrolling as activity
        document.ontouchstart = resetInactivityTimer; // Handle touch events
