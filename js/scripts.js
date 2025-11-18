document.addEventListener('DOMContentLoaded', function() {
    // Sample data for chart (replace with database fetch)
    const cashOnHandData = {
        labels: ['01.04.2025', '02.04.2025', '03.04.2025'],
        datasets: [{
            label: 'Cash on Hand (Rs)',
            data: [493206, 138121, 0], // Update with actual data
            backgroundColor: ['#2c3e50', '#3498db', '#e74c3c'],
            borderColor: ['#2c3e50', '#3498db', '#e74c3c'],
            borderWidth: 1
        }]
    };

    const ctx = document.getElementById('cash-on-hand-chart').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: cashOnHandData,
        options: {
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    });
});