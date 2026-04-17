// Animate cards on load
document.addEventListener('DOMContentLoaded', ()=>{
    const cards=document.querySelectorAll('.card');
    cards.forEach((card,index)=>{
        card.style.opacity=0;
        card.style.transform='translateY(20px)';
        setTimeout(()=>{
            card.style.transition='all 0.6s ease';
            card.style.opacity=1;
            card.style.transform='translateY(0)';
        },150*index);
    });
});

// Dark/Light Mode Toggle
const themeToggle=document.getElementById('themeToggle');
themeToggle.addEventListener('click', ()=>{
    document.body.classList.toggle('dark-mode');
    document.body.classList.toggle('light-mode');
    themeToggle.textContent = document.body.classList.contains('dark-mode') ? '☀️' : '🌙';
});