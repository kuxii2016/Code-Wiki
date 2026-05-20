Hier ist eine professionelle und strukturierte **README.md** für dein Projekt im GitHub-Markdown-Format.

---

# 📝 C# & XAML Code Documentation Generator (v3)

Ein leichtgewichtiger, PHP-basierter Dokumentations-Generator, der C#-Quellcode und XAML-Dateien analysiert, Metadaten extrahiert und ein interaktives Web-Interface zur Navigation bereitstellt. Im Gegensatz zu schwerfälligen Tools wie DocFX benötigt dieser Generator keine kompilierte DLL, sondern arbeitet rein auf Basis von Regex-Parsing.

---

## 🚀 Features

-   **Deep Source Parsing**: Extrahiert Klassen, Structs, Interfaces, Enums, Methoden, Properties und Felder.
-   **Code-Extraktion**: Speichert den tatsächlichen Quellcode-Block für jedes Member direkt in der Dokumentation.
-   **XAML-Integration**: Erkennt WPF/UWP/Xamarin-Views und verknüpft sie automatisch mit ihren Code-Behind-Klassen.
-   **XML-Doc Support**: Liest `/// <summary>` Kommentare aus und bereitet sie grafisch auf.
-   **Interaktives Web-Interface**:
    *   Hierarchische Projekt- und Namespace-Navigation.
    *   Live-Suche (AJAX) für schnelles Finden von Typen und Methoden.
    *   Dark Mode Support.
    *   Statistik-Dashboard (Lines of Code, Dateigrößen, Typen-Zähler).

---

## 🛠 Funktionsweise (Ablauf)

1.  **Scan**: Das Skript `generate.php` durchsucht rekursiv den Ordner `/code`.
2.  **Parsing**: Mittels regulärer Ausdrücke werden die C#-Strukturen und Dokumentationskommentare erkannt.
3.  **JSON-Export**: Alle Informationen werden in einer hochkomprimierten `doc.json` gespeichert.
4.  **Frontend**: Die `index.php` lädt die JSON-Datei und stellt die interaktive Benutzeroberfläche bereit.

---

## 📖 Verwendung

### 1. Vorbereitung
Stelle sicher, dass deine Projektstruktur wie folgt aussieht:
```text
/your-web-root
├── generate.php   # Der Parser
├── index.php      # Das Web-Interface
├── style.css      # Styling (muss vorhanden sein)
├── code/          # Hier dein C# Quellcode (Unterordner erlaubt)
└── doc.json       # Wird automatisch generiert
```

### 2. Dokumentation generieren
Führe den Generator über die Kommandozeile aus:
```bash
php generate.php
```
Das Skript scannt nun alle `.cs` und `.xaml` Dateien und erstellt die `doc.json`.

### 3. Web-Interface aufrufen
Navigiere in deinem Browser zu der `index.php`. Du kannst nun:
*   Durch Namespaces filtern.
*   Die Suche nutzen, um direkt zu Methoden zu springen.
*   Statistiken über die Größe deines Projekts einsehen.

---

## ⚠️ Limitationen

Da das Tool auf **Regex (Regular Expressions)** statt auf einem echten Compiler-Modell (wie Roslyn) basiert, gibt es folgende Einschränkungen:

*   **Komplexität**: Sehr komplexe, verschachtelte Generics oder exotische Attribute könnten das Parsing erschweren.
*   **Formatierung**: Das Tool erwartet einen halbwegs standardkonformen C#-Schreibstil (z. B. Leerzeichen nach `class`-Keywords).
*   **Präprozessor-Direktiven**: `#if DEBUG` Blöcke werden ignoriert; der Parser sieht den gesamten Text.
*   **Abhängigkeiten**: Die `index.php` setzt voraus, dass `search.php` und `class.php` für die Detailansicht und Suchfunktion existieren.

---

## 🔧 Technische Details

### Anforderungen
*   **PHP 8.0+** (wegen `str_starts_with` und anderen modernen Funktionen).
*   Schreibberechtigung im Verzeichnis für die Erstellung der `doc.json`.

### Verarbeitete Dateitypen
| Typ | Beschreibung |
| :--- | :--- |
| `.cs` | Vollständige Analyse von Typen, Membern und XML-Docs. |
| `.xaml` | Erkennung von `x:Class` und Zuordnung zum Namespace/Typ. |

---

## 🎨 UI Anpassung
Die UI nutzt eine `style.css`. Für den **Dark Mode** wird die Klasse `.dark-mode` auf den `<body>` angewendet. Die Farben sind über CSS-Variablen steuerbar.

---

