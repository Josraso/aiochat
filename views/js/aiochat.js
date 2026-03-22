/* AioChat - JS auxiliar */
document.addEventListener('DOMContentLoaded', function () {
    var input = document.getElementById('aiochat-input');
    if (input) {
        input.addEventListener('input', function () {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 80) + 'px';
        });
    }
});
