# עיצוב המערכת — LETS: חוויית המשתמש וה-UI, עם דגש על חנויות-המשנה

> **עדכון:** 2026-10-06. **מקורות:** הקוד עצמו, `docs/ux/00-design-system.md`, `docs/ux/01-navigation.md`,
> `docs/plans/shop-subdomains.md`, ו-`tests/Feature/Tenancy/*`.
> **מה זה המסמך:** תמונה אחת של כל מה שמשתמש רואה ומרגיש במערכת — מי המשתמשים, איפה כל משטח חי,
> איך נראית שפת העיצוב, ובעיקר: איך עובדת **חנות-משנה** שמקבלת כתובת משלה בצורת `<handle>.app.lets.co.il`.
> כל מה שמסומן **"מומלץ"** הוא הערכה שלי ולא משהו שנבנה.
> **סטטוס חנויות-המשנה:** שלבים 1–3 נבנו ב-2026-10-02 ונמצאים מאחורי המתג `SHOP_SUBDOMAINS_ENABLED` (כבוי).
> DNS/תעודה (שלב 0) והשחרור (שלב 4) עדיין פתוחים — ראו §9.

---

## תוכן

1. [המערכת במבט-על](#1-המערכת-במבט-על)
2. [מפת הכתובות — מה חי איפה](#2-מפת-הכתובות)
3. [חנויות-המשנה — חוויית המשתמש מקצה לקצה](#3-חנויות-המשנה-חוויית-המשתמש)
4. [חנויות-המשנה — מפרט ה-UI של המסכים והרכיבים](#4-חנויות-המשנה-מפרט-ui)
5. [שפת העיצוב (Design System)](#5-שפת-העיצוב)
6. [השלד של הממשק (shell)](#6-השלד-של-הממשק)
7. [משטחי הלקוח — storefront ועמודים מאורחים](#7-משטחי-הלקוח)
8. [i18n ו-RTL](#8-i18n-ו-rtl)
9. [מצב, פערים והמלצות](#9-מצב-פערים-והמלצות)
10. [נספח — קבצים, מחרוזות, בדיקות](#10-נספח)

---

## 1. המערכת במבט-על

LETS היא אפליקציית SaaS רב-דיירית (multi-tenant) לסוחרים ישראלים על שער PayPlus, עם שלושה עמודי הכנסה
שאסור להפיל: מקדמה + תשלומים עד לפירעון מלא, מנויים מתחדשים, ואפסיילים אחרי הרכישה על טוקן שמור.
היא מתחברת לשתי פלטפורמות חנות: **Shopify** (אפליקציה ציבורית, מוטמעת) ו-**WooCommerce** (תוסף WordPress).

### 1.1 מי המשתמשים

| משתמש | מי זה | איפה פוגש את המערכת |
|---|---|---|
| **מנהל פלטפורמה** | הבעלים של LETS (`users.is_platform_admin`). אין לו חנות משלו. | `app.lets.co.il` (רשימת החנויות, הגדרות פלטפורמה, Horizon), וכל `<handle>.app.lets.co.il` כשהוא "נכנס" לחנות. |
| **סוחר / חבר צוות** | משתמש שקשור **בדיוק לחנות אחת** (`users.shop_id`). | פאנל הניהול של החנות שלו בלבד — בכתובת שלה, או מוטמע בתוך Shopify Admin / wp-admin. |
| **קונה** | לקוח של החנות. אין לו חשבון ב-LETS. | הווידג'טים בתוך החנות, עמוד התודה, ועמודים מאורחים (אזור אישי, פורטל, מועדון, עדכון כרטיס) — תמיד על `app.lets.co.il`. |

### 1.2 ארבעה משטחים

| # | משטח | טכנולוגיה | למי | שפת עיצוב |
|---|---|---|---|---|
| א | **פאנל הניהול** | Filament 3 בסקין Recharge (`public/css/rc-admin.css`) | סוחר, מנהל פלטפורמה | `--rc-*` (§5) |
| ב | **ממשק מוטמע** | אותו פאנל, בתוך iframe של Shopify Admin (App Bridge) או wp-admin (`/embed/woocommerce/{token}`) | סוחר | `--rc-*`, עם הסתרת הכרום שהמארח כבר מספק |
| ג | **עמודים מאורחים ללקוח** | Blade + JS ללא כרום אדמין: אזור אישי מאורח, פורטל, מועדון, עדכון כרטיס, חזרה מתשלום | קונה | `--la-*`, `--ppp-*`, `--lets-*` (§7) |
| ד | **ווידג'טים בתוך החנות** | Shopify extensions (`extensions/`) ותוסף WordPress (`plugins/`) | קונה | `--la-*`, `--ppu-*`, `--pp-*` — יורשים את הטיפוגרפיה של החנות |

העיקרון שמחבר את הכול: **הטוקנים הם מקור האמת, לא הצבעים.** אין inline CSS באדמין ובחנות (היוצא מן הכלל
היחיד: תבניות אימייל). כל רכיב הוא מחלקה שצורכת משתני CSS, וכל קובץ נפתח בבלוק קבועים.

---

## 2. מפת הכתובות

`APP_URL` נשאר תמיד `https://app.lets.co.il`. הוא מזין כל כתובת "מכונה" (webhooks, callbacks של PayPlus,
ה-API של התוסף, ה-toml של Shopify) ולכן אינו זז. חנות-משנה מקבלת **רק את פאנל הניהול** על כתובת משלה.

| משטח | כתובת כשהמתג דולק | למה |
|---|---|---|
| פאנל ניהול של חנות (ישיר, לא מוטמע), כולל login, 2FA | `https://<handle>.app.lets.co.il/admin` | זו כל המטרה: כתובת אחת לחנות אחת. |
| מסכי פלטפורמה (רשימת חנויות, Email delivery, AI, Observability, Horizon) | `https://app.lets.co.il/admin` | הם לא של אף חנות. Horizon מקושר מחנות-משנה **חזרה לשורש**. |
| Shopify embedded (iframe) | `app.lets.co.il` (ללא שינוי) | ל-toml יש `application_url` אחד. |
| WooCommerce embed (`/embed/woocommerce/{token}`) | `app.lets.co.il` (ללא שינוי) | התוסף מקבע את הבסיס; זה כבר קישור חד-פעמי לחנות. |
| OAuth, webhooks, callbacks של PayPlus, API של התוסף, app proxy, post-purchase/thank-you, נכסי storefront | `app.lets.co.il` (ללא שינוי) | רשומים אצל צד שלישי. |
| אזור אישי מאורח, פורטל, מועדון, קישור עדכון כרטיס, נחיתת קמפיין | `app.lets.co.il` (ללא שינוי) | **החלטת בעלים C** (2026-10-02): עמודי לקוח נשארים על השורש. שלב 5 אופציונלי בעתיד. |
| פיתוח מקומי | `<handle>.app.lets.localhost` | Chrome/Edge פותרים `*.localhost` ל-127.0.0.1 בלי קובץ hosts. |

**איך הקישורים נבנים (ולמה זה חשוב ל-UX):** בתוך בקשה על host של חנות, רק נתיבי האדמין
(`admin/*`, `livewire/*`, `filament/*`) נבנים על אותו host. **כל נתיב אחר** — עמודי לקוח, קישורים חתומים,
callbacks — נבנה על `APP_URL`. כך קישור ללקוח שנוצר ממסך של חנות-משנה לעולם לא יוביל את הקונה אל
`<handle>.app.lets.co.il`, וכתובת מכונה לעולם לא תיטבע עם host של חנות. (`URL::formatHostUsing` ב-`AppServiceProvider`;
מקובע בבדיקה `test_customer_links_built_on_a_store_host_stay_on_the_root`.)

---

## 3. חנויות-המשנה — חוויית המשתמש

### 3.1 ה-handle: "כתובת החנות"

ה-handle הוא התווית השמאלית ביותר של ה-host. למשתמש הוא מוצג תמיד כ**"כתובת החנות"** (`tenancy.handle.label`),
אף פעם לא כ-"handle" או "slug".

| כלל | פירוט | דוגמה |
|---|---|---|
| צורה | תווית DNS אחת: `[a-z0-9]`, מקפים באמצע, 3–63 תווים | `tracki-inc-sp` |
| מקור (Shopify) | ה-handle של myshopify | `tracki-inc-sp.myshopify.com` → `tracki-inc-sp` |
| מקור (WooCommerce) | הדומיין של החנות, נקודות הופכות למקפים, `www.` נזרק | `https://www.sellameir.ussl.co/shop` → `sellameir-ussl-co` |
| ללא מקור שמיש | נגזר מהשם, ואם גם זה ריק — `shop` | — |
| התנגשות | סיומת מספרית | `acme`, `acme-2`, `acme-3` |
| שמורים | `www app api admin platform mail static assets cdn status help docs embed proxy horizon lp` — וכל פחות מ-3 תווים | `app` → `app-2` |
| alias חי | כתובת ישנה של חנות אחרת (עד 30 יום) נחשבת תפוסה | חנות חדשה לא יורשת סימניות של מישהו |

**השלכת UX:** הסוחר **לא מקליד** כתובת בשום שלב. היא נגזרת בהתקנה (`Shop::ensureHandle()` בשני המתקינים,
ו-hook `creating` לכל נתיב אחר), ומוצגת לו בסוף ה-onboarding. שינוי כתובת הוא פעולה של מנהל פלטפורמה בלבד (§3.7).

### 3.2 מסע הסוחר: מהתקנה ועד כניסה יומיומית

```
התקנה / חיבור
   │
   ├─ Shopify: OAuth → ShopInstaller → handle נגזר
   └─ WooCommerce: מנהל פלטפורמה "Add WooCommerce store" → handle נגזר
   │
   ▼
"כתובת ממשק הניהול של החנות שלך:" ─ מוצג ב-3 מקומות (§4.5)
   │
   ▼
כניסה יומיומית ──► https://<handle>.app.lets.co.il/admin/login
   │                      │
   │                      ├─ סיסמה (+ 2FA אם הופעל) → נוחת על Home של החנות
   │                      └─ נכנס דרך app.lets.co.il? → אחרי הכניסה מועבר ל-host של החנות שלו
   │
   ▼
הפאנל — זהה בכל host; הכרטיס בתחתית הסרגל אומר באיזו חנות אתה (§6.1)
```

**הכניסה (login):**

| איפה הסוחר נכנס | מה קורה אחרי סיסמה/2FA | מקובע ב- |
|---|---|---|
| על ה-host של החנות שלו | נשאר שם; נוחת על ה-intended URL או על Home | `test_a_merchant_signing_in_on_their_own_host_stays` |
| על השורש `app.lets.co.il` | מועבר ל-`https://<handle>.app.lets.co.il/admin`; ה-session מתחדש (anti-fixation); intended מהשורש נזרק | `test_a_merchant_signing_in_on_the_root_lands_on_their_store_host` |
| על host של חנות **אחרת** | מועבר הביתה, לחנות שלו | `test_a_merchant_signing_in_on_another_stores_host_is_sent_home` |
| שלב ה-2FA | אותו דבר — שני השלבים פותרים את אותו `HostAwareLoginResponse` | `test_the_two_factor_step_also_lands_a_merchant_on_their_host` |
| מנהל פלטפורמה, בכל host | נשאר איפה שנכנס | `test_a_platform_admin_signing_in_on_the_root_stays_on_the_root` |

### 3.3 "החומה": סוחר שמגיע ל-host של חנות אחרת

ה-host **לעולם לא קובע** מי הדייר. הדייר נקשר מהמשתמש המאומת (`BindTenantFromUser`), וה-host הוא רק
**חומה** מעליו: אם המשתמש והחנות שה-host מציין לא מסכימים, הבקשה נדחית.

| מצב | מה המשתמש חווה |
|---|---|
| סוחר מחובר של חנות A פותח `shop-b.app.lets.co.il/admin` | **מנותק** (ה-session מבוטל, לא רק ה-guard), מקבל הודעת אזהרה, ומועבר ל-`shop-a.app.lets.co.il/admin/login`. שום פיקסל של חנות B לא מצויר. |
| אותו דבר ב-Livewire update (פעולה בטבלה) | 403 שקט אחרי אותו ניתוק (Livewire לא יכול לעקוב אחרי redirect מ-middleware מתמיד). |
| סוחר שה-`shop_id` שלו ריק / החנות הוסרה | 403 עם הודעה: "Your account is not linked to an active store." |
| session מוטמע (Shopify) שלא מסכים עם ה-host | 403. זרימות מוטמעות חיות על השורש בלבד, אז זו צורה שאינה לגיטימית. |

**ההודעה** (Filament notification, סוג warning):

| מפתח | EN | HE |
|---|---|---|
| `tenancy.wall.signed_out` | You were signed out | בוצעה יציאה מהחשבון |
| `tenancy.wall.signed_out_body` | That address belongs to a different store. Sign in to your own store here. | הכתובת הזו שייכת לחנות אחרת. אפשר להתחבר לחנות שלך כאן. |

### 3.4 כתובת שאינה חנות

כל host מתחת לשורש שאינו handle קיים — אות אחת, שם שמור, תווית מקוננת (`a.b.app.lets.co.il`), או כתובת
ישנה שפגה — מקבל **עמוד 404 עצמאי**, לא את טופס הכניסה. הסיבה היא ביטחונית ו-UX-ית כאחת: טופס כניסה על
host שאינו של אף חנות מזמין סוחר להקליד סיסמה במקום שלא יכול להיות שלו. ראו מפרט ב-§4.1.

### 3.5 כתובת ישנה (alias)

כשמנהל פלטפורמה משנה כתובת, הישנה נשמרת ב-`shop_handle_aliases` ל-**30 יום** ועונה ב-**301** לאותו נתיב
על ה-host החדש — סימניות וקישורים שנשלחו במייל לא מתים ברגע. חנות יכולה לקחת בחזרה alias של עצמה;
חנות אחרת לא יכולה לקחת alias חי של מישהו.

### 3.6 מנהל הפלטפורמה: ה-host הוא הכניסה

לפני התכונה, מנהל פלטפורמה "נכנס" לחנות דרך POST שחנה ב-session — ושתי לשוניות על שתי חנויות חלקו
מצב אחד. עכשיו:

| פעולה | עם המתג דולק | עם המתג כבוי (היום בפרודקשן) |
|---|---|---|
| רשימת החנויות → פעולת שורה | **"Open"** — קישור רגיל ל-`https://<handle>.app.lets.co.il/admin` | "Enter shop" — POST שחונה ב-session |
| מחליף החנויות בסרגל העליון | כל פריט הוא קישור ל-host של החנות; "View all shops" ו-"Exit" מובילים לשורש | טפסי POST enter/exit |
| הבאנר "Viewing as" | "Exit" הוא קישור לרשימת החנויות בשורש | טופס POST exit |
| שתי לשוניות, שתי חנויות | עובד: כל לשונית קשורה לחנות שה-host שלה מציין | לא נתמך (session אחד) |
| Horizon | מקושר תמיד לשורש, בלשונית חדשה | — |

מנהל פלטפורמה על host של חנות **נקשר בדיוק לאותה חנות** לאותה בקשה, באותו `Tenant::set` + global scope
של סוחר רגיל. `PlatformContext` קורא **קודם את ה-host, ואז את ה-session** — כך בחירה ישנה ב-session לא
יכולה לדלוף ללשונית על host אחר.

### 3.7 שינוי כתובת (מנהל פלטפורמה בלבד)

מעמוד החנות (`ViewShop`) → פעולת כותרת **"Change address"** → מודאל עם שדה אחד. מפרט ב-§4.3.

### 3.8 session אחד לכל הכתובות

**החלטת בעלים A:** כניסה אחת משרתת את כל ה-subdomains. עוגיית ה-session יושבת על דומיין ההורה
(`.app.lets.co.il`) — מנהל פלטפורמה נכנס פעם אחת ומדלג בין חנויות. הבידוד **לא נשען על העוגייה**: כל
בקשה בודקת מחדש משתמש ↔ host. ההתנהגות הזו נכנסת לתוקף רק עם המתג (עם המתג כבוי שם העוגייה והדומיין
שלה לא משתנים, אז פריסה של הקוד לא מנתקת אף אחד). ביום ההפעלה **כולם מתחברים פעם אחת מחדש**.

**הערה שחשוב להסביר לסוחרים:** משתמש שייך לחנות אחת. סוחר עם שתי חנויות הוא שני משתמשים, ומכיוון
שהעוגייה משותפת, כניסה לחנות B מחליפה את ה-session של חנות A באותו דפדפן. ראו המלצה ב-§9.

---

## 4. חנויות-המשנה — מפרט UI

כל הרכיבים כאן: אפס inline CSS, מחלקות `rc-*` בלבד, מחרוזות דרך `__()` עם מראה ב-`lang/he/tenancy.php`.

### 4.1 עמוד "החנות לא נמצאה" (404)

**קובץ:** `resources/views/tenancy/no-such-store.blade.php` · **סגנון:** `components/embedded.css` (`.rc-embed-notice*`),
אותו מסגור של עמוד 410 "הקישור פג" של ה-embed.

```
┌──────────────────── canvas --rc-bg ────────────────────┐
│                                                        │
│        ┌──────── card 30rem, --rc-card ────────┐       │
│        │          [ let's lockup ]              │       │
│        │                                        │       │
│        │          No such store                 │ ← --rc-type-title
│        │  There is no store at <host>. Check    │ ← --rc-type-body
│        │  the address you were given.           │
│        │                                        │
│        │     Go to the LETS sign-in page        │ ← --rc-type-caption, קישור לשורש
│        └────────────────────────────────────────┘       │
└────────────────────────────────────────────────────────┘
```

| פרט | ערך |
|---|---|
| אנטומיה | לוגו → כותרת → גוף עם ה-host שהוקלד → רמז עם קישור ל-`app.lets.co.il/admin` |
| טוקנים | `--rc-bg --rc-card --rc-border --rc-radius-card --rc-shadow-card --rc-type-title/body/caption --rc-space-5/6` |
| מצבי קצה | `<meta name="robots" content="noindex,nofollow">`; `dir` לפי locale; סטטוס HTTP 404 |
| מחרוזות | `tenancy.no_such_store.title` / `.body` (`:host`) / `.hint` |
| HE | "החנות לא נמצאה" · "אין חנות בכתובת :host. כדאי לבדוק את הכתובת שקיבלת." · "למסך הכניסה של LETS" |

### 4.2 הודעת החומה (sign-out)

Filament notification במצב **warning** (אמבר), נשלחת רגע לפני ה-redirect ומופיעה על עמוד הכניסה של
החנות הנכונה. אין רכיב ייעודי — זהו רכיב ההתראות הסטנדרטי של הפאנל, בפלטת הסטטוס של הסקין.

### 4.3 מודאל "שינוי כתובת"

**איפה:** `ViewShop` → פעולת כותרת `tenancy.handle.edit.action` ("Change address" / "שינוי כתובת").

| חלק | EN | HE |
|---|---|---|
| כותרת | Change the store address | שינוי כתובת החנות |
| תיאור | The store admin moves to the new address. The old address keeps redirecting to it for 30 days. | ממשק הניהול של החנות יעבור לכתובת החדשה. הכתובת הישנה תמשיך להפנות אליה במשך 30 ימים. |
| שדה | Store address (מולא מראש בכתובת הנוכחית) | כתובת החנות |
| עזרה | Lowercase letters, digits and dashes, 3–63 characters. The admin opens at `<address>.app.lets.co.il` | אותיות לטיניות קטנות, ספרות ומקפים, 3–63 תווים. ממשק הניהול ייפתח בכתובת `<כתובת>.app.lets.co.il` |
| אישור | Save address | שמירת הכתובת |
| הצלחה (notification) | Saved. The store admin is now at :url | נשמר. ממשק הניהול של החנות נמצא עכשיו בכתובת :url |

**שגיאות ולידציה** (מוצגות כ-notification שגיאה, כי השדה חי בתוך `mountedActionsData`):

| מפתח | EN | HE |
|---|---|---|
| `too_short` | The address must be at least 3 characters. | הכתובת צריכה להכיל לפחות 3 תווים. |
| `format` | Use lowercase letters, digits and dashes only, starting and ending with a letter or digit (up to 63 characters). | יש להשתמש באותיות לטיניות קטנות, ספרות ומקפים בלבד… |
| `reserved` | That address is reserved for the platform. Choose another. | הכתובת הזו שמורה לפלטפורמה. יש לבחור כתובת אחרת. |
| `taken` | Another store already uses that address. | חנות אחרת כבר משתמשת בכתובת הזו. |

### 4.4 רשימת החנויות ועמוד החנות (פלטפורמה)

**רשימת החנויות** (`ShopResource`, קבוצת ניווט "Platform" — נראית למנהל פלטפורמה בלבד):

| עמודה | הערה |
|---|---|
| Store domain | `displayDomain()`: דומיין Shopify → דומיין Woo → שם → `Shop #id`; ניתן לחיפוש ומיון |
| **Store address** (handle) | ניתן לחיפוש; ניתן להסתרה |
| Name, Platform (badge), Status (badge), Plan | — |
| PayPlus (אייקון בוליאני), Products, Active subs, Processed revenue | מיושרים לסוף |
| Installed, Uninstalled | האחרון מוסתר כברירת מחדל |

פעולות שורה: **Open** (או "Enter shop" כשהמתג כבוי) · View · Delete (אדום, מודאל אישור עם אזהרה מלאה).
מסננים: status / platform / plan.

**עמוד החנות** (`ViewShop`): רשת KPI (מוצרים, מנויים פעילים, הכנסות) → חיבור WooCommerce (לחנויות Woo) →
פעילות אחרונה (Timeline). בעמודת הצד, כרטיס "Account" עם שורות מפתח/ערך:

```
Store domain      tracki-inc-sp.myshopify.com    ← .rc-ident (LTR מבודד)
Store address     tracki-inc-sp                  ← .rc-ident
Admin URL         https://tracki-inc-sp.app.lets.co.il/admin   ← .rc-ident
Name · Status [badge] · Platform · Plan · PayPlus · Shopify/WordPress · Installed · Uninstalled
```

פעולות כותרת: Open · **Change address** · (Woo) Connection token · Download plugin · Test connection.

### 4.5 שלוש נקודות שבהן הסוחר רואה את הכתובת שלו

| איפה | מה מוצג | מקובע ב- |
|---|---|---|
| **Home — באנר first-run** (`rc-banner`, רקע `--rc-blue-bg`): מופיע כל עוד אין חיבור PayPlus | שורה נוספת בגוף הבאנר: "Your store's admin address:" + הכתובת כקישור `.rc-link.rc-ident` בלשונית חדשה. מופיע רק עם המתג דולק ודייר קשור. | `test_the_first_run_banner_shows_the_store_address` |
| **WooCommerce connect reveal** (מודאל חד-פעמי אחרי הנפקת טוקן) | מתחת ל-3 צעדי ההתקנה: "Your store's admin address:" + קישור `dir="ltr"` | `test_woocommerce_connect_reveals_the_store_admin_address` |
| **הוספת חבר צוות** (`CreateTeamMember`) | notification "נוצר" עם גוף "They sign in at :url" → `https://<handle>.app.lets.co.il/admin/login` | `test_adding_a_colleague_says_where_they_sign_in` |
| CLI `lets:user:create` | מדפיס את כתובת הכניסה של החנות | `test_the_create_user_command_prints_the_store_login` |

מחרוזות: `tenancy.onboarding.admin_url_intro` ("כתובת ממשק הניהול של החנות שלך:"), `tenancy.team.sign_in_at`
("הכניסה שלהם היא בכתובת :url").

### 4.6 הסרגל העליון כשמנהל פלטפורמה נמצא בחנות

| רכיב | מצב "נכנס" | מחלקות |
|---|---|---|
| **מחליף החנויות** (לפני ה-user pill) | טריגר עם אייקון חנות + `displayDomain()` של החנות הנוכחית; רקע `--rc-blue-bg` ומסגרת `--rc-blue` | `.rc-shop-switcher__trigger--entered` |
| תפריט המחליף | כותרת, עד **8 חנויות אחרונות** (הנוכחית עם ✓, השאר קישורים ל-host), "View all shops", "Exit" | `.rc-shop-switcher__item/--current/__viewall/__exit` |
| **באנר "Viewing as"** (תחילת הגוף, בכל עמוד) | פס אמבר: "Viewing as :shop" (`--rc-type-body-strong`) + "Acting on the merchant's behalf — every change is logged." (caption) + כפתור Exit פיל עם מסגרת אמבר שמתמלא ב-hover | `.rc-platform-banner*` (`components/platform.css`) |

סוחר רגיל לא רואה אף אחד מהשניים — ה-Blade לא מצייר כלום.

### 4.7 כרטיס החנות בתחתית הסרגל הצדי

נראה לכל מי שיש לו דייר קשור (סוחר, או מנהל פלטפורמה שנכנס). מוסתר כשהסרגל מכווץ לאייקונים.

```
┌ rc-sidebar-foot (קו עליון --rc-border) ┐
│  ⚙ Settings                             │ ← פריט ניווט מוצמד
│  (T)  Tracki                            │ ← עיגול 30px --rc-blue עם האות הראשונה
│       Shopify                           │ ← --rc-type-caption, --rc-ink-muted
└─────────────────────────────────────────┘
```

השם מגיע מ-`BusinessName::for($shop)`: `business_name` שהסוחר הקליד → `name` מההתקנה → ה-handle של
Shopify באות גדולה → "Our Store".

---

## 5. שפת העיצוב

**מקור אמת:** `docs/ux/00-design-system.md` (מפרט) ↔ `resources/css/filament/admin/theme.css` (ההצהרה
**היחידה** של טוקני `--rc-*`) → נבנה ל-`public/css/rc-admin.css` (ללא Vite, `build-theme.mjs`) ונטען לפאנל
דרך `FilamentAsset`. הסגנון: **Recharge** — כחול אחד על קנבס שמנת חם, כרטיסים לבנים עם קו-שיער וצל רך,
צפיפות גבוהה, Heebo לעברית ולטינית באותו גופן.

### 5.1 שלושת הכללים

1. **טוקן → משתנה CSS → מחלקת רכיב.** מפרט מסך לעולם לא מציין hex, רק שם טוקן.
2. **אפס inline CSS** באדמין ובחנות. היוצא מן הכלל היחיד: HTML של אימייל (`resources/views/emails/*` וגוף שהסוחר ערך).
3. **CONST-at-top.** כל קובץ נפתח בבלוק הטוקנים שהוא צורך.

### 5.2 צבע

| תפקיד | משתנה | ערך | שימוש |
|---|---|---|---|
| מותג (Recharge blue) | `--rc-blue` | `#3B5BDB` | CTA ראשי, פריט ניווט פעיל, קישורים, focus ring, קו tab פעיל |
| מותג hover/pressed | `--rc-blue-600` | `#2F4BC4` | — |
| שטיפת מותג | `--rc-blue-bg` | `#EEF2FE` | רקע ניווט פעיל, שורה נבחרת, באנר first-run, מחליף חנויות במצב "נכנס" |
| קנבס | `--rc-bg` | `#F8F8F5` | שמנת חמה מאחורי כל הכרטיסים |
| כרטיס | `--rc-card` / `--rc-surface` | `#FFFFFF` | כרטיסים, טבלאות, סרגל צדי, מודאלים |
| משטח שקוע | `--rc-surface-sunk` | `#FAFAF8` | כותרות טבלה, פאנלים פנימיים |
| קו-שיער | `--rc-border` / `--rc-border-soft` / `--rc-border-strong` | `#E6E6E1` / `#F0F0EE` / `#D4D4CE` | גבולות, מפרידי שורות, שדות |
| דיו | `--rc-ink` / `--rc-ink-muted` / `--rc-ink-on-primary` | `#1A1A1A` / `#6B7280` / `#FFFFFF` | — |
| **סימן המותג** | `--rc-logo-blue` / `--rc-logo-orchid` | `#0066FF` / `#EE82EE` | שני חצאי-הדיסק של הלוגו בלבד; לא משטח נושא |

**סטטוס** — כל גוון בא בשלשה: בסיס (נקודות ומילויים) · `-bg` (רקע פיל) · `-ink` (טקסט על הרקע, כהה יותר
כדי ש-12px יחזיקו 4.5:1):

| גוון | בסיס / bg / ink | משמעות |
|---|---|---|
| ירוק | `#1E9E6A` / `#E6F4EE` / `#157A52` | active, completed, succeeded, דלתא חיובית |
| אפור | `#6B7280` / `#F0F0EE` / `#4B5563` | paused, cancelled, pending, refunded, draft |
| טורקיז | `#0FA3A3` / `#E2F5F5` / `#0B6E6E` | NEW, flow live, info |
| אדום | `#D64545` / `#FBE9E9` / `#B93232` | failed, שגיאות הזמנה, פעולות הרסניות |
| אמבר | `#C77700` / `#FBF1E0` / `#8A5300` (+ `--rc-amber-border #EED9AE`) | retry scheduled, awaiting payment, באנר "Viewing as" |

סדרות גרפים (`--rc-series-1..6`): כחול, טורקיז, אמבר, אדום, סגול `#7746EC`, אפור — נבדלות **בבהירות**, לא רק בגוון;
אדום שמור לכשלים ולנטישה בלבד. מפת-חום: המותג ב-8/18/30/55%.

### 5.3 טיפוגרפיה (Heebo לשתי השפות)

| תפקיד | משתנה | גודל/משקל |
|---|---|---|
| כותרת עמוד | `--rc-type-title` | 22px / 700 |
| ערך KPI | `--rc-type-kpi` | 28px / 600 |
| כותרת כרטיס | `--rc-type-h` | 18px / 600 |
| תת-כותרת | `--rc-type-subh` | 15px / 600 |
| גוף / גוף מודגש | `--rc-type-body` / `--rc-type-body-strong` | 14px / 400 · 600 |
| תווית | `--rc-type-label` | 12px / 600, **UPPERCASE** + 0.04em — בעברית ללא ריווח-אותיות |
| כיתוב | `--rc-type-caption` | 12px / 400 |

### 5.4 ריווח, רדיוס, צל, פריסה, תנועה

| קבוצה | טוקנים |
|---|---|
| ריווח | `--rc-space-1..8` = 4 · 8 · 12 · 16 · 24 · 32 · 48 · 64 px |
| רדיוס | כרטיס 12px · פקד 8px · פיל 999px |
| צל | `--rc-shadow-card` (מנוחה) · `--rc-shadow-hover` · `--rc-shadow-pop` (תפריטים) · `--rc-shadow-thumb` (אגודל של segmented control) · `--rc-ring` (focus, כחול 30%) · `--rc-scrim` (מסך מודאל 35%) |
| פריסה | סרגל צדי 248px · סרגל עליון 56px · חיפוש 380px · רוחב תוכן מרבי 1200px · gutter 32px · פיצול עמוד פרטים 70/30 |
| תנועה | `--rc-motion-ease` cubic-bezier(.2,.7,.2,1) · grow 450ms · draw 700ms · fade 400ms · stagger 15ms — הכול מתיישב תוך ~900ms |

### 5.5 מצב כהה

**אין** מצב כהה לפאנל הניהול (סקין בהיר אחד). לעמוד המועדון (`loyalty.css`) ולאזור האישי (`lets-account.css`)
**יש** `data-theme="dark"` / `auto` כי הם חיים בתוך האתר של הסוחר, ושם הנושא הוא שלו.

### 5.6 מלאי הרכיבים (אנטומיה ומצבים — בקצרה)

| רכיב | מצבים | מחלקה / קובץ |
|---|---|---|
| KPI card | loading (skeleton) · empty (`—`) · error (`!` + retry) · דלתא ▲/▼ עם כיוון-טוב מוצהר לכל מדד | `.rc-kpi*` `kpi-card.css` |
| Status badge (פיל) | green/gray/teal/red/amber; מפת סטטוס→גוון אחת ב-00 §4.2 | `.rc-badge` `badge.css` |
| Data table row | hover (שטיפה כחולה) · selected + bulk bar · loading · empty | `data-table.css` `table.css` |
| + Add filter / chip | default · open · applied · applied-hover | — |
| CTA ראשי / משני / הרסני | hover · pressed · loading · disabled; הרסני = שני שלבים או מודאל | `<x-rc.cta>` `buttons.css` |
| Tabs (קו תחתון) | active · default · hover · locked (מנעול + tooltip) | — |
| Accordion | collapsed · expanded · loading · empty; chevron מתהפך ב-RTL | `accordion.css` |
| Right-sidebar panel | info · list · actions · timeline | `detail.css` |
| Subscription / address card | לפי סטטוס; failed = פס אדום בצד ההתחלה | — |
| Confirmation dialog | כל פעולה שמזיזה כסף: cancel, pause, charge now, refund, send link | — |
| Empty state | first-run · filtered-no-results · error — **לעולם לא חולקים טקסט** | — |
| Timeline row | success / failure / info / previewable (אימייל ב-iframe srcdoc מבודד); **לעולם לא מציג `invoice_url`** | `.rc-timeline` `timeline.css` |
| Flow Builder node | trigger / offer / branch; selected (טבעת כחולה) · invalid (טבעת אדומה) | `post-purchase.css` |
| Consent disclosure | חובה לפני כל חיוב עתידי על טוקן שמור | — |
| Banner (first-run / attention) | שטיפה כחולה / אמבר | `.rc-banner` `.rc-attention` |
| Segmented control | EN\|עב, Daily\|Weekly\|Monthly; אגודל לבן מורם | `.rc-lang-switch` `.rc-pp-segment` |
| User pill | אות ראשונה על כחול + שם פרטי + chevron; השם נעלם מתחת ל-640px | `.rc-user-pill` |
| Embed notice | כרטיס 30rem ממורכז על הקנבס (404 חנות, 410 קישור) | `.rc-embed-notice` |

### 5.7 איך הסקין יושב על Filament

- `rc-admin.css` נטען **לפני** `app.css` של Filament, ולכן כל כלל שמשנה אלמנט של Filament מעוגן ב-`.fi-body`
  (אחרת utility של Tailwind באותו משקל מנצח).
- רמפת ה-primary של Filament (`--primary-50..950`) ורמפת האפור ממופות מחדש על המותג ועל אפור חם —
  כך כפתורים, toggles והתראות "ילידיים" יורשים את הפלטה בחינם. `AdminPanelProvider::colors()` מצהיר את
  אותם ערכים כדי שהמשתנים ה-inline של Filament ישוו.
- הפאנל רץ ב-**SPA mode** (`wire:navigate`): השלד, הגופנים ו-App Bridge נשארים חיים בין עמודים.
- נכסים נטענים עם `?v=filemtime` כדי שסקין חדש לא ייתקע בקאש.

---

## 6. השלד של הממשק

### 6.1 הסרגל הצדי (248px, לבן, קו-שיער בצד הסיום)

```
┌──────────────────────┐
│ [let's]              │ ← לוגו: שני חצאי-דיסק + "let's" במשקל 900
│ ─ PLATFORM ──────── │ ← רק למנהל פלטפורמה
│   Shops              │
│   Email delivery…    │
│ ─ CUSTOMERS ─────── │ ← כותרות קבוצה = תוויות 11px, לא אקורדיונים
│   Customers          │
│   Subscriptions      │ ← פריטים 34px; פעיל = שטיפה כחולה + פס 3px בצד ההתחלה
│   Campaigns …        │
│ ─ PRODUCTS ──────── │
│ ─ PAYMENTS ──────── │
│ ─ CROSS-SELL ────── │
│ … (גולל)             │
├──────────────────────┤
│ ⚙ Settings           │ ← מוצמד לתחתית
│ (T) Tracki · Shopify │ ← כרטיס החנות (§4.7)
└──────────────────────┘
```

סדר הקבוצות הוא **נתון**, לא פיזור של `navigationSort`: `platform → customers → products → payments → upsell → settings`
(`AdminPanelProvider::NAV_GROUP_ORDER`). תגיות ספירה (חיובים שנכשלו, חשבוניות) הן פילים עגולים אדומים/אמבר.

### 6.2 הסרגל העליון (56px, לבן)

| מיקום | רכיב | התנהגות |
|---|---|---|
| התחלה | **חיפוש** (380px, Ctrl/Cmd+K, debounce 300ms) | לקוחות, מנויים והזמנות של **החנות הקשורה בלבד**; נכשל-סגור ללא דייר |
| סיום | **פעמון** (רק עם דייר) | חיובים שנכשלו + מסמכים שדורשים טיפול; נקודה אדומה 8px |
| | **עזרה** | `mailto:` לתמיכת LETS (אין צ'אט עדיין) |
| | **EN \| עב** | פיל שני מקטעים; לחיצה שומרת locale וטוענת מחדש — כל השלד מתהפך ל-RTL |
| | **מחליף חנויות** | מנהל פלטפורמה בלבד (§4.6) |
| | **User pill** | תפריט: פרופיל, Security (2FA), יציאה |

### 6.3 Settings כ-hub

פריט אחד בסרגל, שפותח **אינדקס טקסט שקט ברוחב 230px** בתוך העמוד (ללא אייקונים, ללא שטיפה; פעיל =
תווית בכחול). `/admin/settings` מפנה לעמוד הראשון שהצופה רשאי לראות — חנות Shopify Payments ללא
מסך PayPlus נוחתת על הבא. העמודים: חיבור PayPlus, הגדרות חיוב, חשבוניות, אימייל, אזור לקוח, מראה
האפסייל, אלמנטים ב-storefront, חברי צוות — כל אחד `shop_id`-scoped.

### 6.4 מה קיים היום בפאנל (מפת מסכים)

| אזור | מסכים (`app/Filament/Pages` + `Resources`) |
|---|---|
| בית ואנליטיקה | HomeDashboard · Analytics (subscribers / payments / retention) |
| לקוחות | Customers · CustomerDetail (כולל "view as customer" לאזור האישי האמיתי) · Campaigns · NewsletterStudio |
| מנויים | SubscriptionResource · SubscriptionContractResource (Shopify) · BulkEditSubscriptions · ImportSubscriptions · GiftOrders |
| תשלומים | PaymentLedgerResource · PaymentRecovery · IssuedDocumentResource (חשבוניות) |
| מוצרים | ProductResource · ProductDetail |
| אפסייל | PostPurchaseOffers · FlowBuilder · AccountOfferResource · ManageUpsellAppearance · StorefrontElements |
| מועדון | ManageLoyalty · LoyaltyMembers · LoyaltyReferralResource |
| הגדרות | ManagePayPlusConnection · ManageBillingSettings · ManageInvoicing · ManageMailSettings · ManageCustomerArea · TeamMemberResource · TwoFactorSecurity (בתפריט המשתמש) |
| פלטפורמה | ShopResource · ManagePlatformMail · ManagePlatformAi · ManageAiPrompts · ObservabilityDashboard · Horizon (קישור) |

### 6.5 הממשק המוטמע

- **Shopify:** App Bridge ראשון ב-`<head>`; session token מאומת → התקנה מנוהלת + כניסה אוטומטית. הדייר נגזר
  **רק** מה-JWT. בקשה מוטמעת שאיבדה session מוקפצת ל-App Bridge לטוקן חדש, לא לטופס login (מבוי סתום לסוחר ללא סיסמה).
- **WordPress:** `<meta name="lets-embedded">` ב-`<head>` → הסקין מסתיר את תפריט המשתמש ואת מתג השפה, כי wp-admin
  כבר מספק אותם. קישור שפג → עמוד 410 במסגור `.rc-embed-notice`.

---

## 7. משטחי הלקוח

עמודי הלקוח **אינם** צורכים את סקין האדמין (חוץ ממסגור העמוד המאורח). לכל משפחה יש טוקנים משלה,
scoped לשורש הרכיב — כי הם יושבים בתוך ערכת העיצוב של הסוחר ואסור להם לדלוף אליה.

| משטח | קובץ | משפחת טוקנים | מאפיין עיצובי מרכזי |
|---|---|---|---|
| **האזור האישי** (Woo native + Shopify customer-account + מאורח) | `public/account/lets-account.css` (מקור קנוני; מועתק לתוסף ב-build) | `--la-*` | **הגופן הוא של החנות** כברירת מחדל (`data-font="theme"`); opt-in ל-Heebo/system. מתגים לכל חנות: `data-theme` (light/dark/auto), `data-density`, `data-card` (flat/raised); accent ורדיוס כמשתנים. רדיוס 16px, "נושם כמו דף חשבון, לא כמו טבלת הגדרות". |
| **עמוד מאורח** (`account/hosted.blade.php`) | `components/campaigns.css` `.rc-hosted*` | `--rc-*` למסגרת בלבד | פס עליון "Signed in as ••••@…" + יציאה; בפנים אותו `lets-account.js` ואותו CSS של החנות — העמוד **חי**, לא תצוגה מקדימה. רוחב 60rem. |
| **פורטל** (קישור חתום) | `public/css/portal.css` | `--ppp-*` | עמודה אחת 640px, mobile-first, **RTL-first**; accent `#4f46e5` |
| **מועדון לקוחות** | `public/css/loyalty.css` | `--lets-*` | עמוד שלם (App Proxy / iframe); hero עם דרגה, נקודות ופס התקדמות; `data-theme="dark"` |
| **אפסייל בעמוד תודה** | `public/css/upsell-widget.css` + `lets-ppu.css` בתוסף | `--ppu-*` | כרטיס 480px; וריאנטים media-side, dark-outline, bundle |
| **ווידג'ט מקדמה/מנוי בעמוד מוצר** (Woo) | `plugins/…/assets/css/lets.css` | `--pp-*` | — |

**ערכת ה-UI ל-storefront** (`docs/design/storefront-kit.html`, נבנית ב-`docs/design/build-kit.php`): גיליון אחד
בעברית — "כל מה שהלקוח רואה בחנות — במקום אחד" — שמטמיע את ה-CSS הקנוני בזמן בנייה (אין עותק
שמתיישן). חמישה פרקים: האזור האישי (LA-01…14, כולל RTL/dark ופאנל כניסה), האפסייל (PPU-01…06),
עמוד המוצר (PP-01…03), מועדון ושותפים (LC-01…03), ו"לתשומת לבך". כל אלמנט גם כקובץ עצמאי ב-`docs/design/html/`.

**תצוגות מקדימות באדמין הן העמוד האמיתי:** `/admin/account/preview`, `/admin/loyalty/preview` ו-`/admin/upsell/preview/{platform}/{offer}`
מריצים את אותו renderer ואותו stylesheet עם נתוני דוגמה, כך שכיוון צבע בהגדרות לא יכול לסטות מהמשטח החי.
עורך "אזור לקוח" הוא טופס משמאל + תצוגה דביקה מימין (760px; עמודה אחת מתחת ל-1100px).

---

## 8. i18n ו-RTL

- אנגלית היא ברירת המחדל; כל מחרוזת דרך `__()`; `lang/he/*` משקף מפתח-מפתח. ה-locale נבחר לפי
  `?locale=` → session → `en` (`SetAdminLocale`), ו-`<html dir>` מתהפך אוטומטית לעברית.
- **RTL הוא היפוך, לא עיצוב מחדש:** מאפיינים לוגיים בלבד (`inline-start/end`), בלי stylesheet מראה.
  החריג היחיד שהוא "לא היפוך" הוא קנבס ה-Flow Builder; גרפים **מתהפכים בגאומטריה**, לא ב-`scaleX(-1)`.
- שני כלי עזר לערכים בתוך משפט עברי: `.rc-iso` (מספרים, כסף, תאריכים — מבודדים בכיוון העמוד, עם LRM)
  ו-`.rc-ident` (אימיילים, URL-ים, דומיינים, **handles** — תמיד LTR, מיושר לסוף ב-RTL). **כל כתובת חנות
  מוצגת ב-`.rc-ident`.**
- תוויות `--rc-type-label`: UPPERCASE הוא no-op בעברית, וריווח-האותיות מוסר (נראה שבור בעברית).
- עמודי הלקוח הפוכים בדגש: **RTL-first**, LTR הוא ההיפוך.
- שתי מערכות מחרוזות שאסור לבלבל: UI (`__()`, placeholders `:name`) לעומת תבניות אימייל (`{{token}}` דרך
  `strtr()` בלבד, לעולם לא `Blade::render()`).

---

## 9. מצב, פערים והמלצות

### 9.1 מה נבנה ומה פתוח (חנויות-המשנה)

| שלב | מצב | פירוט |
|---|---|---|
| 1 Handle | **נבנה** | `shops.handle` + `shop_handle_aliases` (מיגרציה `2026_10_02_000001`, backfill לכל חנות), כללים, גזירה, סיומות, שינוי עם alias |
| 2 Resolution | **נבנה** | `ResolveShopFromHost` ראשון ו-Livewire-persistent, החומה ב-`BindTenantFromUser`, `HostAwareLoginResponse`, קישורי Open/מחליף/באנר, `trustHosts`, עוגייה משותפת |
| 3 Links | **נבנה** | `Shop::adminUrl()` הבונה היחיד; first-run, Woo reveal, חבר צוות, CLI |
| 0 Ops | **פתוח** | DNS: `*.app.lets.co.il CNAME qscc7xky.up.railway.app` (DNS-only) + רשומת `_acme-challenge` אם Railway מבקש; Railway → web → Networking → `*.app.lets.co.il`; לוודא שהתוכנית תומכת ב-wildcard; `curl -I https://anything.app.lets.co.il/up` → 200 + תעודה תקפה |
| 4 Release | **פתוח** | הרצת המיגרציה על Postgres, שער review, בדיקת עשן על שתי חנויות אמיתיות; env: `SHOP_SUBDOMAINS_ENABLED=true`, `SESSION_DOMAIN` יכול להישאר ריק (ברירת מחדל `.app.lets.co.il`), ואם `SESSION_COOKIE` מוגדר ב-Railway — לשנות את ערכו באותו רגע; להודיע שכולם מתחברים פעם אחת |
| 5 | **נדחה** | עמודי לקוח על host של החנות — הוחלט לא (C) |

שינוי אחד **לא** מאחורי המתג: `X-Forwarded-Host` אינו כותרת proxy מהימנה יותר. זה תיקן באג סמוי שבו
בקשות App Proxy של Shopify בנו `asset()`/`url()` על הדומיין של החנות במקום שלנו.

### 9.2 ממצאי UX (הערכה שלי — לא נבנה)

1. **עמוד 404 "החנות לא נמצאה" תמיד באנגלית.** `ResolveShopFromHost` רץ ראשון, לפני `StartSession`
   ו-`SetAdminLocale`, וזורק את התשובה — ולכן `app()->getLocale()` הוא תמיד ברירת המחדל (`en`). סוחר עברי
   שטעה באות מקבל עמוד באנגלית. **מומלץ:** לקרוא `Accept-Language` (או עוגיית locale ישירות, בלי session)
   בתוך ה-view ולבחור `he` כשמתאים.
2. **עמוד הכניסה על host של חנות זהה לשורש.** אותו כרטיס, אותו לוגו LETS, אותה כותרת "Sign in" — ה-host
   בשורת הכתובת הוא הרמז היחיד לאיזו חנות נכנסים. `RequestedShop` ידוע עוד לפני אימות, אז אפשר בבטחה
   להציג את שם העסק והאות הראשונה (כמו כרטיס החנות בסרגל) מעל הטופס: "כניסה ל-Tracki". זה גם מה
   שהודעת "They sign in at …" לחבר צוות מכינה אותו לראות.
3. **הודעת החומה היא toast חולף.** אחרי ניתוק והפניה בין hosts היא מופיעה פעם אחת על עמוד הכניסה
   ונעלמת. **מומלץ:** גם שורה קבועה בכרטיס הכניסה (למשל דרך `?reason=foreign_host`), כדי שסוחר שמצמץ יבין
   למה הוא פתאום מחוץ לחשבון.
4. **משתמש = חנות אחת, עוגייה אחת.** סוחר עם שתי חנויות הוא שני משתמשים; כניסה לחנות B מנתקת את A
   באותו דפדפן. **מומלץ:** לכתוב את זה בעזרה של "חברי צוות", ובהמשך לשקול חברות רב-חנותית.
5. **"Open" מרשימת החנויות נפתח באותה לשונית.** מנהל הפלטפורמה מאבד את הרשימה. מכיוון ש"שתי לשוניות,
   שתי חנויות" היא צורה נתמכת עכשיו, **מומלץ** לפתוח בלשונית חדשה.
6. **מחליף החנויות מציג 8 חנויות אחרונות.** במאות חנויות צריך חיפוש בתוך המחליף (החיפוש בסרגל העליון הוא
   של הדייר הקשור, לא מאתר חנויות).
7. **אין שינוי כתובת בשירות עצמי.** handle שנגזר מ-myshopify יכול להיות מכוער (`tracki-inc-sp`). התוכנית
   הזכירה "הסוחר יכול לקצר ב-onboarding" ל-Woo, אבל זה לא נבנה — היום רק מנהל פלטפורמה משנה. אפשר לשקול
   שינוי חד-פעמי מודרך ב-onboarding, עם אותם כללי ולידציה.
8. **מודאל ה-Woo connect reveal** מדבר Tailwind utilities ולא `rc-*` (מותר לפי הכותרת שלו, אבל זה המשטח
   היחיד שלא מדבר באוצר המילים של הרכיבים). עדיפות נמוכה.

### 9.3 פערים כלליים מול הסקיצה המאושרת

- צילומי המסך ב-`docs/screenshots/` קודמים לעיצוב-מחדש של 2026-09-27 (מותג כטקסט "PayPlus Subscriptions",
  קבוצות ניווט מתקפלות) — לרענן אחרי שלב 4.
- מהזיכרון של הפרויקט, עדיין שונים מהסקיצה: פריסת קנבס ה-Flow Builder, שורת טווח-תאריכים/MRR ב-Home,
  ומסננים בסגנון chips.

---

## 10. נספח

### 10.1 קבצים

| שכבה | קבצים |
|---|---|
| דומיין | `app/Domain/Tenancy/ShopHandle.php` (כללים, גזירה, ייחודיות) · `ShopHandleChanger.php` · `ShopHandleResolver.php` (קאש 5 דק' להחלטה, לא למודל) · `ShopHosts.php` (מפת ה-hosts, בוני קישורים, `trustedHostPatterns`) · `ShopHandleBackfill.php` |
| Middleware | `app/Http/Middleware/ResolveShopFromHost.php` · `BindTenantFromUser.php` (החומה) |
| תמיכה | `app/Support/RequestedShop.php` · `PlatformContext.php` (host קודם, session אחריו) · `app/Http/Responses/HostAwareLoginResponse.php` |
| מודלים | `app/Models/Shop.php` (`handle`, `ensureHandle()`, `adminUrl()`, ניסיון חוזר על התנגשות של handle **שנגזר**) · `ShopHandleAlias.php` (TTL 30 יום) |
| תצורה | `config/tenancy.php` (`admin_root_host`, `subdomains_enabled`, `extra_trusted_hosts`) · `config/session.php` (דומיין העוגייה לפי המתג) · `bootstrap/app.php` (TrustHosts, ללא `X-Forwarded-Host`) |
| UI | `resources/views/tenancy/no-such-store.blade.php` · `filament/platform/shop-switcher.blade.php` · `viewing-as-banner.blade.php` · `filament/partials/sidebar-shop.blade.php` · `filament/resources/shop-resource/pages/view-shop.blade.php` · `woo-connection.blade.php` · `filament/pages/home-dashboard.blade.php` |
| CSS | `resources/css/filament/admin/theme.css` (טוקנים) · `components/shell.css` · `platform.css` · `logo.css` · `embedded.css` · `data-table.css` (`.rc-ident`) |
| מחרוזות | `lang/en/tenancy.php` ↔ `lang/he/tenancy.php` · `lang/*/platform.php` (switcher, banner) · `lang/*/nav.php` |
| תכנון | `docs/plans/shop-subdomains.md` (כולל "As built") |

### 10.2 הבדיקות שמקבעות את ההתנהגות (`tests/Feature/Tenancy/`)

- `ShopSubdomainTest` — 27 בדיקות: קשירה על ה-host שלך, ניתוק על host זר (גם ב-Livewire), מנהל פלטפורמה
  scoped לחנות ה-host, 404 ולא login, 301 ל-alias עם שמירת הנתיב, המתג כבוי → לא נפתר כלום, נחיתה אחרי
  login/2FA, embedded לא מושפע, קישורים (Open, switcher, banner, לקוח → שורש), hosts מהימנים,
  `X-Forwarded-Host` לא בוחר חנות, עוגייה חוצת-hosts בפרודקשן.
- `ShopHandleTest` — 22 בדיקות: גזירה (Shopify/Woo/fallback/קיצור), שמורים, סיומות, alias חי לעומת פג, מרוץ
  התקנות מקבילות, backfill אידמפוטנטי, המיגרציה, שינוי מהעמוד ודחיית שמור.
- `ShopAdminLinksTest` — 5 בדיקות: הבונה היחיד, Woo reveal, first-run, חבר צוות, CLI.
- `TrustedHostsTest` — 3 בדיקות: endpoints של מכונה עונים בשני מצבי המתג; host לא רשום נדחה רק עם המתג.
