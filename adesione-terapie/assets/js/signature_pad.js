// Signature pad utilities for therapy wizard
(function () {
    const pads = {};

    function getContextSizes(canvas) {
        const rect = canvas.getBoundingClientRect();
        const width = rect.width || canvas.offsetWidth || canvas.clientWidth || 300;
        const height = rect.height || canvas.offsetHeight || canvas.clientHeight || 150;
        return { width, height };
    }

    function initSignaturePad(canvasId, clearButtonId) {
        const canvas = document.getElementById(canvasId);
        if (!canvas) return;

        const ctx = canvas.getContext('2d');
        const state = {
            canvas,
            ctx,
            drawing: false,
            hasDrawing: false
        };

        const setCanvasSize = () => {
            const existingData = state.hasDrawing ? canvas.toDataURL('image/png') : (canvas.dataset.existingImage || '');
            const ratio = window.devicePixelRatio || 1;
            const { width, height } = getContextSizes(canvas);
            canvas.width = width * ratio;
            canvas.height = height * ratio;
            ctx.setTransform(ratio, 0, 0, ratio, 0, 0);

            if (existingData) {
                const img = new Image();
                img.onload = () => {
                    ctx.clearRect(0, 0, width, height);
                    ctx.drawImage(img, 0, 0, width, height);
                    state.hasDrawing = true;
                };
                img.src = existingData;
            }
        };

        setCanvasSize();

        const getPoint = (event) => {
            const rect = canvas.getBoundingClientRect();
            const clientX = event.touches ? event.touches[0].clientX : event.clientX;
            const clientY = event.touches ? event.touches[0].clientY : event.clientY;
            return {
                x: clientX - rect.left,
                y: clientY - rect.top
            };
        };

        const startDrawing = (event) => {
            event.preventDefault();
            state.drawing = true;
            const { x, y } = getPoint(event);
            state.lastX = x;
            state.lastY = y;
        };

        const draw = (event) => {
            if (!state.drawing) return;
            event.preventDefault();
            const { x, y } = getPoint(event);
            ctx.lineWidth = 2;
            ctx.lineCap = 'round';
            ctx.strokeStyle = '#000';
            ctx.beginPath();
            ctx.moveTo(state.lastX, state.lastY);
            ctx.lineTo(x, y);
            ctx.stroke();
            state.lastX = x;
            state.lastY = y;
            state.hasDrawing = true;
        };

        const stopDrawing = () => {
            state.drawing = false;
        };

        const clearCanvas = () => {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            state.hasDrawing = false;
        };

        canvas.addEventListener('mousedown', startDrawing);
        canvas.addEventListener('mousemove', draw);
        canvas.addEventListener('mouseup', stopDrawing);
        canvas.addEventListener('mouseleave', stopDrawing);

        canvas.addEventListener('touchstart', startDrawing, { passive: false });
        canvas.addEventListener('touchmove', draw, { passive: false });
        canvas.addEventListener('touchend', stopDrawing);
        canvas.addEventListener('touchcancel', stopDrawing);

        window.addEventListener('resize', setCanvasSize);

        const clearButton = document.getElementById(clearButtonId);
        if (clearButton) {
            clearButton.addEventListener('click', clearCanvas);
        }

        pads[canvasId] = state;
    }

    function getSignatureDataUrl(canvasId) {
        const pad = pads[canvasId];
        if (!pad || !pad.hasDrawing) return null;
        return pad.canvas.toDataURL('image/png');
    }

    window.initSignaturePad = initSignaturePad;
    window.getSignatureDataUrl = getSignatureDataUrl;
})();
