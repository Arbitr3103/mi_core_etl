// Простой скрипт для принудительного обновления CSS
const fs = require("fs");
const path = require("path");

// Добавляем комментарий с timestamp в CSS файл
const cssPath = path.join(__dirname, "src", "index.css");
const cssContent = fs.readFileSync(cssPath, "utf8");

// Удаляем старый timestamp если есть
const cleanContent = cssContent.replace(/\/\* Updated: .* \*\/\n?/, "");

// Добавляем новый timestamp
const newContent = `/* Updated: ${new Date().toISOString()} */\n${cleanContent}`;

fs.writeFileSync(cssPath, newContent);
console.log("CSS updated with new timestamp");
