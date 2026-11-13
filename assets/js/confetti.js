/* Mini-Konfetti für Meilensteine */
function fireConfetti() {
    var c = document.createElement('canvas');
    c.style.cssText = 'position:fixed;inset:0;pointer-events:none;z-index:99999';
    document.body.appendChild(c);
    var x = c.getContext('2d');
    c.width = innerWidth; c.height = innerHeight;
    var cols = ['#ff2ec4', '#00f0ff', '#f0c850', '#22c55e', '#ff7518', '#a855f7'];
    var ps = [];
    for (var i = 0; i < 140; i++) ps.push({ x: c.width / 2 + (Math.random() - .5) * 160, y: c.height / 2, vx: (Math.random() - .5) * 16, vy: -Math.random() * 15 - 4, s: Math.random() * 6 + 3, r: Math.random() * 6.3, rs: (Math.random() - .5) * .3, col: cols[i % cols.length], life: 130 + Math.random() * 50 });
    (function loop() {
        x.clearRect(0, 0, c.width, c.height);
        var alive = false;
        for (var i = 0; i < ps.length; i++) {
            var p = ps[i];
            if (p.life-- <= 0) continue;
            alive = true;
            p.x += p.vx; p.y += p.vy; p.vy += .35; p.r += p.rs;
            x.save(); x.translate(p.x, p.y); x.rotate(p.r);
            x.globalAlpha = Math.min(1, p.life / 50);
            x.fillStyle = p.col; x.fillRect(-p.s / 2, -p.s / 2, p.s, p.s * .6);
            x.restore();
        }
        if (alive) requestAnimationFrame(loop);
        else c.remove();
    })();
}
