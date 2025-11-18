document.addEventListener('DOMContentLoaded', function () {
    const config = {
        inactivityPeriod: 160000, // 160 seconds
        countdownDuration: 30000 // 30 seconds
    };

    let inactivityTime = function () {
        let time, countdownTimer;
        let timeLeft = config.countdownDuration / 1000; // Seconds for display

        // Get modal and elements
        let modalElement = document.getElementById('inactivityModal');
        const countdownDisplay = document.getElementById('countdown');
        const stayLoggedInBtn = document.getElementById('stayLoggedIn');

        // Initialize Bootstrap modal
        let bootstrapModal = modalElement ? new bootstrap.Modal(modalElement, {
            backdrop: 'static',
            keyboard: true
        }) : null;

        // Reset timer on user activity
        function resetTimer() {
            clearTimeout(time);
            clearInterval(countdownTimer);
            if (bootstrapModal) {
                bootstrapModal.hide();
            }
            timeLeft = config.countdownDuration / 1000;
            if (countdownDisplay) {
                countdownDisplay.textContent = timeLeft;
            }
            time = setTimeout(startCountdown, config.inactivityPeriod - config.countdownDuration);
        }

        // Start countdown timer
        function startCountdown() {
            if (!bootstrapModal || !countdownDisplay) return;
            timeLeft = config.countdownDuration / 1000;
            countdownDisplay.textContent = timeLeft;
            countdownDisplay.setAttribute('aria-live', 'assertive');
            bootstrapModal.show();
            countdownTimer = setInterval(() => {
                timeLeft--;
                countdownDisplay.textContent = timeLeft;
                if (timeLeft <= 0) {
                    clearInterval(countdownTimer);
                    bootstrapModal.hide();
                    logout();
                }
            }, 1000);
        }

        // Logout function
        function logout() {
            window.location.href = 'logout.php';
        }

        // Event listeners for user activity
        window.onload = resetTimer;
        document.onmousemove = resetTimer;
        document.onkeydown = resetTimer;
        document.onclick = resetTimer;
        document.onscroll = resetTimer;
        document.onmousedown = resetTimer;

        // Stay logged in button and keyboard support
        if (stayLoggedInBtn) {
            stayLoggedInBtn.addEventListener('click', resetTimer);
            stayLoggedInBtn.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    resetTimer();
                }
            });
        }

        // Handle modal close via keyboard (Escape key)
        if (modalElement) {
            modalElement.addEventListener('hidden.bs.modal', resetTimer);
        }
    };

    inactivityTime();
});