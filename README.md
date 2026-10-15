# silverstripe-scheduler

Zentralisierter Task-Scheduler für **Silverstripe 6**.

Statt für jeden `BuildTask` einen eigenen System-Cron anzulegen, verwaltet
dieses Modul eine im CMS pflegbare Liste geplanter Jobs. Ein einziger
Server-Cron ruft alle paar Minuten den Master-Runner auf, der ermittelt,
welche Jobs fällig sind, und startet jeden fälligen Job in einem
**isolierten Subprozess** — Fehler und Timeouts einzelner Jobs blockieren
dadurch keine nachfolgenden Jobs.

---

## Features

- **CMS-Verwaltung** über `SingleRecordAdmin` — kein `ModelAdmin`.
- **Benutzerfreundlicher Cron-Editor** (`CronScheduleField`) mit Presets
  (alle X Minuten / stündlich / täglich / wöchentlich / monatlich),
  Wochentag-Chips und Live-Preview. Speichert intern eine
  Standard-Cron-Expression.
- **Feiertagsoption**: pro Job kann „an deutschen Feiertagen überspringen"
  aktiviert werden (`GermanHolidays`-Service, Gauß-Osterformel — keine
  externen Dependencies).
- **Sortierbare Job-Liste** per Drag & Drop
  (`GridFieldOrderableRows` auf `Sort`).
- **„Jetzt ausführen"-Button** pro Zeile — startet den Job asynchron und
  aktualisiert den Zeilenstatus per JS-Polling ohne Seiten-Reload.
- **„Alle Tasks nacheinander ausführen"-Bar** über dem Grid — segmentierte
  Progressbar (Farbe pro Task, Breite proportional zur letzten Laufzeit),
  hebt die laufende Zeile hervor und aktualisiert nach jedem Task die
  Spalten „Letzter Lauf" / „Letzter Status".
- **Subprozess-Isolation**: jeder Job läuft in einem eigenen
  `sake`-Aufruf; Fatal-Errors oder Segfaults betreffen nur den Job selbst.
- **Timeout pro Job** (`MaxRuntimeSeconds`).
- **Stale-Detection**: hängengebliebene Jobs werden nach Ablauf ihres
  Timeouts automatisch als `TIMEOUT` markiert und wieder freigegeben.
- **Global Lock**: verhindert Overlap, wenn ein Runner-Lauf länger dauert
  als das Cron-Intervall.
- **Historie** aller Läufe (Status, Exit-Code, Dauer, stdout/stderr) im
  CMS einsehbar — pro Job und global.

---

## Installation

```bash
composer require clesson-de/silverstripe-scheduler
sake dev/build flush=all
```

Server-Cron (Beispiel alle 5 Minuten):

```
*/5 * * * * /path/to/project/vendor/bin/sake scheduler-run >/dev/null 2>&1
```

---

## Konfiguration

Beispiel `app/_config/scheduler.yml`:

```yaml
Clesson\Silverstripe\Scheduler\Tasks\SchedulerRunTask:
  cli_user_email: 'admin@example.com'

Clesson\Silverstripe\Scheduler\Tasks\ScheduledJobExecuteTask:
  cli_user_email: 'admin@example.com'

Clesson\Silverstripe\Scheduler\Services\JobRunnerService:
  default_subprocess_timeout: 900
  sake_binary: ''            # leer = auto-detect $BASE_PATH/vendor/bin/sake
```

Die `cli_user_email` gehört einem CMS-User mit den Rechten, die die
angesprochenen BuildTasks brauchen. Der Runner setzt diesen User als
`Security::setCurrentUser()`, bevor er die Ziel-Task ausführt. Die gleiche
E-Mail wird per `Config::modify()` an die Ziel-Task propagiert, damit
Tasks mit eigener `cli_user_email`-Config nicht separat gepflegt werden
müssen.

---

## Verwendung

1. Im CMS **„Scheduler"** öffnen.
2. Auf dem Tab **Jobs** einen neuen Eintrag anlegen:
   - **Beschreibung** (optional): freier Text.
   - **Task**: Dropdown mit allen registrierten `BuildTask`-Klassen (außer
     Runner und Executor selbst — sonst gäbe es Endlosschleifen).
   - **Zeitplan**: über den `CronScheduleField`-Editor konfigurieren.
   - **An Feiertagen überspringen** (optional).
   - **Max. Laufzeit** (`0` = unbegrenzt).
   - **Aktiviert**: Toggle.
   - **Reihenfolge**: per Drag & Drop im Grid.
3. Der Master-Runner erledigt den Rest. Ergebnisse landen im Tab **Verlauf**.

---

## Architektur

```
Server-Cron  ──►  sake scheduler-run
                       │
                       ▼
                  SchedulerRunTask
                       │
                       ▼
                  JobRunnerService::runDueJobs()
                       │
                       ├─► globaler File-Lock
                       │
                       ├─► für jeden fälligen Job:
                       │     subprocess: sake scheduled-job-execute --jobID=X [--runID=Y]
                       │        │
                       │        ▼
                       │     ScheduledJobExecuteTask
                       │        │
                       │        ▼
                       │     $task = Injector::create($job->TaskClass)
                       │     $task->run($input, new PolyOutput(..., new BufferedOutput()))
                       │
                       └─► pro Lauf: ScheduledJobRun mit Status, Dauer,
                                     Exit-Code, stdout, stderr
```

`JobRunnerService::dispatchJobDetached()` startet einen Job zusätzlich
per `nohup … &` fully-detached — genutzt von den beiden UI-Buttons, damit
das Backend nicht auf das Ende der Task wartet.

---

## Ordnerstruktur

```
silverstripe-scheduler/
├── _config/config.yml           # extra_requirements + JobRunnerService defaults
├── _config.php
├── client/dist/                 # ausgelieferte Assets (composer vendor-expose)
│   ├── CronScheduleField.js/.css
│   ├── RunNowButton.js/.css     # AJAX „Jetzt ausführen"-Button
│   └── RunAllButton.js/.css     # „Alle Tasks nacheinander"-Bar
├── lang/                        # de.yml, en.yml
└── src/
    ├── Admins/SchedulerAdmin.php
    ├── Constants/JobStatus.php
    ├── Forms/
    │   ├── CronScheduleField.php
    │   ├── GridFieldConfig_JobsInSchedulerAdmin.php
    │   ├── GridFieldConfig_RunsInSchedulerAdmin.php
    │   ├── GridFieldConfig_RunsInScheduledJob.php
    │   └── GridField/GridFieldAction_RunNow.php
    ├── Models/
    │   ├── SchedulerConfig.php   # SingleRecord
    │   ├── ScheduledJob.php
    │   └── ScheduledJobRun.php
    ├── Services/
    │   ├── JobRunnerService.php
    │   └── GermanHolidays.php
    └── Tasks/
        ├── SchedulerRunTask.php          # command: scheduler-run
        └── ScheduledJobExecuteTask.php   # command: scheduled-job-execute
```

---

## Entwickler-Hinweise

Wenn du am Modul selbst arbeitest oder es erweiterst, beachte folgende
Besonderheiten:

### BuildTask ist SS6-API

- `protected static string $commandName` (**nicht** `$segment`, das ist
  SS4/5-Legacy). Ohne `$commandName` wird die Task nicht als
  Symfony-Command registriert und `sake dev/tasks/…` findet sie nicht.
- Signatur ist `run(InputInterface $input, PolyOutput $output): int`.
  Wer eine Ziel-Task innerhalb einer eigenen Task ausführt, muss
  `BufferedOutput` in ein `PolyOutput` wrappen:
  ```php
  $capture = new BufferedOutput();
  $output = new PolyOutput(PolyOutput::FORMAT_ANSI, $verb, false, $capture);
  $task->run($input, $output);
  ```
- `validate()`-Return-Type ist `SilverStripe\Core\Validation\ValidationResult`.

### `sake`-CLI-Aufrufe

Argumente **niemals** in Quotes zusammenfassen — Symfony Console würde den
ganzen String als Command-Namen interpretieren:

```php
// FALSCH:
sprintf('%s "dev/tasks/scheduled-job-execute --jobID=%d"', $sake, $id);
// RICHTIG:
sprintf('%s scheduled-job-execute --jobID=%d', $sake, $id);
```

Detached-Aufrufe laufen als:

```
nohup <sake> scheduled-job-execute --jobID=X --runID=Y > /tmp/…log 2>&1 &
```

`Process::run()` kehrt sofort zurück, weil die Shell forkt und exit't.

### Neue Task registrieren

Es reicht, einen `BuildTask` im Projekt zu haben. Er erscheint automatisch
im Task-Dropdown, sobald er im Class-Manifest registriert ist. Runner und
Executor filtern sich selbst heraus, damit keine Endlosschleifen möglich sind.

Wenn deine Task eine eigene `cli_user_email`-Config verwendet, brauchst du
nichts weiter zu tun — der Executor propagiert seine E-Mail per
`Config::modify()->set($taskClass, 'cli_user_email', $email)` vor dem
`run()`-Aufruf.

### Frontend-Assets

- Vanilla-JS, kein Build-Step, kein Framework — liegt direkt in
  `client/dist/` und wird per `extra.expose` von Composer publiziert.
- Nach Änderungen an Assets: `composer vendor-expose` und ggf.
  Browser-Cache leeren (Silverstripe hängt kein `?m=…`-Query an).
- Assets werden über `LeftAndMain.extra_requirements_*` in `_config/config.yml`
  geladen. Reine Modul-Assets, keine Framework-JS-Abhängigkeit (außer
  jQuery, das im CMS ohnehin da ist).

### JSON-Endpoints im `SchedulerAdmin`

| Route                              | Methode | Zweck                                       |
| ---------------------------------- | ------- | ------------------------------------------- |
| `admin/scheduler/job-status/$ID`   | GET     | Polling-Status eines Jobs (Buttons + Bar).  |
| `admin/scheduler/dispatch-job/$ID` | POST    | Fire-and-forget-Start eines Jobs (Bar).     |

Beide sind in `$allowed_actions` + `$url_handlers` registriert und laufen
nur eingeloggt (via Admin-Auth der `SingleRecordAdmin`).

### GridField-Zellen-Selektoren

Live-Updates hängen an CSS-Klassen wie `.col-LastRunAt`, `.col-LastStatus`.
Diese werden vom `GridFieldDataColumns` automatisch aus dem Feldnamen
generiert — Vorsicht beim Umbenennen von DB-Feldern; ohne Anpassung im JS
laufen die Live-Updates ins Leere.

Zeilen werden per `tr[data-id="<ID>"]` gefunden (SS6-Default), mit
Fallback auf einen Edit-Link, dessen `href` `/item/<ID>/` enthält.

### CronScheduleField

- Wandelt Nutzereingaben in eine Standard-Cron-Expression
  (`*/15 * * * *`, `0 3 * * *`, …).
- Widget ist rein clientseitig; der PHP-Teil ist eine schlanke Ableitung
  von `TextField`, die die Assets lädt.
- Vor dem Speichern wird der String zusätzlich mit
  `Cron\CronExpression::isValidExpression()` validiert.
- Round-Trip-Parsing im JS unterstützt die 5 Presets — unbekannte
  Ausdrücke fallen auf den Advanced-Modus zurück (Direkteingabe).

### Feiertage

`GermanHolidays::isHoliday(\DateTimeInterface $d): bool` deckt die
9 bundesweit gesetzlichen Feiertage ab. Osterbezogene Termine kommen aus
der Gauß'schen Osterformel — keine `ext-calendar`-Extension nötig.
Landes- oder firmenspezifische Feiertage lassen sich per Extension oder
Subclass ergänzen.

### Detached-Subprozesse & Betriebssystem

Das `nohup … &`-Pattern funktioniert nur unter Unix (Linux, macOS, WSL,
DDEV-Container). Auf reinem Windows ohne WSL läuft der detached-Modus
**nicht** — dort bitte den synchronen `runDueJobs()` per Cron nutzen.

### Composer-Path-Repos & Class-Manifest

Wird das Modul während der Entwicklung per Path-Repo mit `symlink: true`
in ein Silverstripe-Projekt gemappt, entstehen unter Umständen
Class-Manifest-Duplikate (Silverstripe scannt sowohl den Projekt-Root als
auch `vendor/`). Abhilfe: das Modul im Root-`composer.json` als `replace`
deklarieren und das Path-Repo entfernen, sobald es unverändert ist.

### Übersetzungen

Alle CMS-Strings sind über `_t(__CLASS__ . '.KEY', 'Default')` in
`lang/de.yml` + `lang/en.yml` gepflegt. Beim Hinzufügen neuer Felder oder
Buttons unbedingt beide Sprachdateien synchron halten.

---

## Grenzen

- Kleinste Auflösung: das Intervall des Server-Crons (typisch 5 Min.).
- Langlaufende Jobs blockieren keine anderen, aber der Master-Runner
  wartet auf ihr Ende. Bei Jobs > Cron-Intervall bewusst
  `MaxRuntimeSeconds` setzen.
- Kein Multi-Node-Locking: wenn mehrere App-Server denselben Cron laufen
  lassen, muss auf einem File-Share oder DB-Lock zusätzlich synchronisiert
  werden.

## Lizenz

MIT
