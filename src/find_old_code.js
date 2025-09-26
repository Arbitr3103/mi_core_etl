/**
 * Скрипт для поиска старого кода с IP адресом на сайте WordPress
 * Запустите этот код в консоли браузера (F12) на странице zavodprostavok.ru
 */

console.log("🔍 Поиск старого кода с IP адресом 178.72.129.61...");
console.log("================================================");

// Функция для поиска в тексте элемента
function searchInText(element, searchTerm) {
  const results = [];

  if (element.innerHTML && element.innerHTML.includes(searchTerm)) {
    results.push({
      element: element,
      tagName: element.tagName,
      content: element.innerHTML.substring(0, 200) + "...",
      location: element.outerHTML.substring(0, 100) + "...",
    });
  }

  return results;
}

// Поиск скриптов с старым IP
console.log("1. 🔍 Поиск скриптов с IP адресом:");
const oldScripts = [];
Array.from(document.scripts).forEach((script, index) => {
  if (script.src && script.src.includes("178.72.129.61")) {
    oldScripts.push(script);
    console.log(`❌ Найден старый скрипт #${index + 1}:`, script.src);
    console.log("   Элемент:", script);
  }
});

if (oldScripts.length === 0) {
  console.log("✅ Старые скрипты не найдены");
} else {
  console.log(`❌ Найдено ${oldScripts.length} старых скриптов`);
}

// Поиск в содержимом всех элементов
console.log("\n2. 🔍 Поиск IP адреса в содержимом элементов:");
const elementsWithOldIP = [];
document.querySelectorAll("*").forEach((element) => {
  const results = searchInText(element, "178.72.129.61");
  elementsWithOldIP.push(...results);
});

if (elementsWithOldIP.length === 0) {
  console.log("✅ IP адрес в содержимом не найден");
} else {
  console.log(`❌ Найдено ${elementsWithOldIP.length} элементов с IP адресом:`);
  elementsWithOldIP.forEach((item, index) => {
    console.log(`   ${index + 1}. ${item.tagName}:`, item.element);
    console.log(`      Содержимое: ${item.content}`);
  });
}

// Поиск переменных JavaScript с API URL
console.log("\n3. 🔍 Поиск переменных JavaScript:");
const jsVariables = [
  "API_BASE_URL",
  "API_URL",
  "apiBaseUrl",
  "apiUrl",
  "BASE_URL",
  "CountryFilter",
];

jsVariables.forEach((varName) => {
  try {
    if (window[varName]) {
      console.log(`📋 Найдена переменная ${varName}:`, window[varName]);
      if (
        typeof window[varName] === "string" &&
        window[varName].includes("178.72.129.61")
      ) {
        console.log(`❌ Переменная ${varName} содержит старый IP!`);
      }
    }
  } catch (e) {
    // Переменная не существует
  }
});

// Поиск в localStorage и sessionStorage
console.log("\n4. 🔍 Поиск в localStorage и sessionStorage:");
for (let i = 0; i < localStorage.length; i++) {
  const key = localStorage.key(i);
  const value = localStorage.getItem(key);
  if (value && value.includes("178.72.129.61")) {
    console.log(`❌ localStorage["${key}"] содержит старый IP:`, value);
  }
}

for (let i = 0; i < sessionStorage.length; i++) {
  const key = sessionStorage.key(i);
  const value = sessionStorage.getItem(key);
  if (value && value.includes("178.72.129.61")) {
    console.log(`❌ sessionStorage["${key}"] содержит старый IP:`, value);
  }
}

// Поиск в CSS
console.log("\n5. 🔍 Поиск в CSS стилях:");
Array.from(document.styleSheets).forEach((sheet, index) => {
  try {
    Array.from(sheet.cssRules || sheet.rules || []).forEach(
      (rule, ruleIndex) => {
        if (rule.cssText && rule.cssText.includes("178.72.129.61")) {
          console.log(
            `❌ CSS правило в таблице стилей #${index + 1}, правило #${
              ruleIndex + 1
            }:`
          );
          console.log("   ", rule.cssText);
        }
      }
    );
  } catch (e) {
    // Некоторые таблицы стилей могут быть недоступны из-за CORS
    console.log(
      `⚠️  Не удалось проверить таблицу стилей #${
        index + 1
      } (возможно, внешняя)`
    );
  }
});

// Поиск в мета-тегах
console.log("\n6. 🔍 Поиск в мета-тегах:");
document.querySelectorAll("meta").forEach((meta) => {
  if (
    (meta.content && meta.content.includes("178.72.129.61")) ||
    (meta.name && meta.name.includes("178.72.129.61"))
  ) {
    console.log("❌ Найден мета-тег со старым IP:", meta);
  }
});

// Итоговый отчет
console.log("\n📊 ИТОГОВЫЙ ОТЧЕТ:");
console.log("================================================");
console.log(`Старые скрипты: ${oldScripts.length}`);
console.log(`Элементы с IP в содержимом: ${elementsWithOldIP.length}`);

if (oldScripts.length > 0 || elementsWithOldIP.length > 0) {
  console.log("\n❌ НАЙДЕНЫ ПРОБЛЕМЫ! Необходимо исправить:");

  if (oldScripts.length > 0) {
    console.log("\n🔧 Для исправления скриптов:");
    oldScripts.forEach((script, index) => {
      console.log(`${index + 1}. Замените: ${script.src}`);
      console.log(
        `   На: ${script.src.replace(
          "http://178.72.129.61",
          "https://api.zavodprostavok.ru"
        )}`
      );
    });
  }

  console.log("\n📋 Рекомендации:");
  console.log("1. Проверьте functions.php темы WordPress");
  console.log("2. Проверьте плагины для вставки кода");
  console.log("3. Проверьте настройки темы");
  console.log("4. Поищите в редакторе страниц/записей");
} else {
  console.log("✅ Старый код не найден! Проблема может быть в другом месте.");
}

console.log("\n🔗 Полезные ссылки для проверки:");
console.log("- Тест API: https://api.zavodprostavok.ru/api/countries.php");
console.log("- Документация: https://api.zavodprostavok.ru/api/");
console.log(
  "- Тест endpoints: https://api.zavodprostavok.ru/api/test_api_endpoints.html"
);

// Функция для автоматического исправления (осторожно!)
window.fixOldScripts = function () {
  console.log("🔧 Попытка автоматического исправления...");

  oldScripts.forEach((script) => {
    const newSrc = script.src.replace(
      "http://178.72.129.61",
      "https://api.zavodprostavok.ru"
    );
    console.log(`Заменяем ${script.src} на ${newSrc}`);

    // Удаляем старый скрипт
    script.remove();

    // Создаем новый
    const newScript = document.createElement("script");
    newScript.src = newSrc;
    document.head.appendChild(newScript);
  });

  console.log("✅ Автоматическое исправление завершено");
  console.log(
    "⚠️  Это временное решение! Найдите и исправьте источник проблемы."
  );
};

if (oldScripts.length > 0) {
  console.log("\n🛠️  Для временного исправления выполните: fixOldScripts()");
}
