const puppeteer = require('puppeteer');
const fs = require('fs');

(async () => {
    const browser = await puppeteer.launch({
        headless: true,
        args: [
            '--no-sandbox',
            '--disable-setuid-sandbox',
            '--disable-gpu',
            '--disable-dev-shm-usage',
            '--disable-features=site-per-process',
        ]
    });

    const page = await browser.newPage();

    await page.goto('https://stockx.com', { waitUntil: 'networkidle2' });

    // Замість page.waitForTimeout()
    await new Promise(resolve => setTimeout(resolve, 15000));

    const cookies = await page.cookies();
    const clearance = cookies.find(c => c.name === 'cf_clearance');

    if (clearance) {
        fs.writeFileSync('cf_cookie.json', JSON.stringify(clearance, null, 2));
        console.log('✅ cf_clearance saved:', clearance.value);
    } else {
        console.log('❌ cf_clearance not found.');
        fs.writeFileSync('debug-cookies.json', JSON.stringify(cookies, null, 2));
    }

    await browser.close();
})();
