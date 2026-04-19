## Flusso – Richiesta di congedo (con gestione 40 ore)

**Attori:** Allievo, Genitore/Tutore, Capo laboratorio, Docente di classe (eventuali docenti interessati)

### 1. Creazione richiesta


- L’allievo inserisce una richiesta di congedo indicando:
  - date
  - ore/lezioni coinvolte
  - motivo
- Stato iniziale: `In attesa firma tutore`
- Il sistema invia automaticamente un’email al genitore/tutore con link sicuro.
- **Comportamento di default:**
  - Le ore del congedo **rientrano nel conteggio delle 40 ore annuali** dello studente.
  - La regola delle 40 ore **non è hardcoded** e può essere modificata in casi eccezionali.

### 2. Conferma del genitore/tutore


- Se il tutore firma:
  - Stato: `Firmata`
- Se non firma:
  - La richiesta resta in stato `In attesa firma tutore`
  - Rimane comunque visibile e gestibile dal capo laboratorio e dal docente di classe.

### 3. Azioni del capo laboratorio e del docente di classe

Il capo laboratorio (e, se necessario, il docente di classe) può intervenire in qualsiasi momento, anche in assenza della firma del tutore, ed effettuare le seguenti azioni:

- **Pre-approvazione**
  - Utilizzabile anche senza firma del tutore.
  - Stato: `Pre-approvata`
  - Commento facoltativo (es. “Genitore momentaneamente non reperibile”).
  - Le ore continuano a rientrare nelle 40 ore fino a eventuale modifica.

- **Approvazione**
  - Possibile anche senza firma del tutore (override).
  - Stato: `Approvata`
  - Commento **obbligatorio** in caso di approvazione senza firma (es. “Conferma ricevuta telefonicamente dal tutore”).
  - Invio email al docente di classe ed eventualmente ai docenti interessati.

- **Gestione eccezioni sulle 40 ore**
  - Il capo laboratorio o il docente di classe può decidere che il congedo:
    - rientri nel conteggio delle 40 ore (default)
    - **non rientri nel conteggio delle 40 ore** (es. funerali, eventi riconosciuti, casi particolari)
  - In caso di esclusione dalle 40 ore:
    - è richiesto un **commento obbligatorio**
    - la decisione viene tracciata nello storico.

- **Richiesta documentazione (es. talloncino)**
  - Stato: `Documentazione richiesta`
  - Commento obbligatorio.
  - Invio email all’allievo per caricare la documentazione richiesta.
  - Dopo il caricamento:
    - Stato: `In valutazione`.

- **Rifiuto**
  - Stato: `Rifiutata`
  - Commento obbligatorio.
  - Notifica all’allievo ed eventualmente al tutore.

- **Reinvio email al tutore**
  - Possibilità di reinviare manualmente l’email di conferma al genitore/tutore senza ricreare la richiesta.

### 4. Chiusura e registrazione

- Dopo l’approvazione definitiva:
  - Il docente di classe riceve la notifica.
  - Stato finale: `Congedo registrato`
- Se il tutore firma dopo una pre-approvazione o un’override:
  - La firma viene registrata senza modificare le decisioni già prese.
- Tutte le modifiche relative:
  - al conteggio delle 40 ore
  - alle approvazioni manuali
  - alle eccezioni
  vengono **tracciate nello storico**, con utente, data/ora e commento.

## Flusso 2 – Segnalazione di assenza (con certificati e 40 ore)

**Attori:** Allievo, Docente di classe, Genitore/Tutore

### 1. Segnalazione assenza

- L’allievo segnala l’assenza indicando:

  - data/e
  - ore che mancherà
  - motivo (es. malattia, altro)
- Stato iniziale: `Segnalata (in attesa firma tutore)`
- **Comportamento di default:**

  - Le ore di assenza **rientrano nel conteggio delle 40 ore annuali**.

### 2. Conferma del genitore/tutore

- Il sistema invia un’email al genitore/tutore con link sicuro.
- Se il tutore firma:

  - Stato: `Firmata (in attesa validazione docente)`
- Se non firma:

  - L’assenza rimane `In attesa firma tutore`
  - Rimane comunque visibile e gestibile dal docente di classe.
- Il docente può in qualsiasi momento:

  - **reinviare manualmente l’email di conferma** al tutore
  - **approvare direttamente con osservazioni** (override firma tutore)

### 3. Gestione certificato medico

- In caso di assenza per malattia:
  - Dopo **3 giorni consecutivi di assenza** il **certificato medico è obbligatorio**.
- L’allievo può caricare il certificato medico:
  - entro il termine previsto
  - anche prima della scadenza dei 5 giorni
- Se il certificato viene caricato e accettato:
  - l’assenza **non rientra nel conteggio delle 40 ore**.

### 4. Gestione del termine

- L’allievo ha **5 giorni (esclusi weekend)** per:
  - far firmare l’assenza dal tutore
  - caricare eventuale certificato medico
- Il sistema:
  - mostra un countdown
  - notifica l’allievo prima della scadenza.

### 5. Poteri del docente di classe

Il docente di classe può, anche in presenza di eccezioni:

- **Validare l’assenza**

  - Stato: `Giustificata`
  - Possibilità di inserire un’osservazione.
  - Può decidere se l’assenza:
    - rientra nelle 40 ore (default)
    - **non rientra nelle 40 ore** (commento obbligatorio).
- **Concedere una proroga**

  - Estendere il termine oltre i 5 giorni.
  - Inserire un commento obbligatorio (es. “certificato in arrivo”).
  - L’assenza non diventa arbitraria fino alla nuova scadenza.
- **Approvare senza firma del tutore**

  - In caso di problemi tecnici o conferma ricevuta verbalmente.
  - Commento obbligatorio.


### 6. Scadenza e assenza arbitraria

- Se il termine (eventualmente prorogato) scade senza:
  - firma del tutore
  - certificato medico obbligatorio
- L’assenza diventa:
  - Stato: `Arbitraria`
  - Le ore restano conteggiate nelle 40 ore.
- Notifica automatica a:
  - allievo
  - docente di classe.

## Flusso 3 – Segnalazione di ritardo

**Attori:** Allievo, Docente di classe, Genitore/Tutore

1. **Segnalazione ritardo**

   - L’allievo segnala il ritardo indicando la durata.
   - Se il ritardo è ≤ 15 minuti, viene registrato come ritardo.
   - Se il ritardo è > 15 minuti, viene considerato come ora di assenza + ritardo.
   - Stato iniziale: `Segnalato (in attesa firma tutore)`.
   - Il docente di classe riceve una notifica via email.
2. **Conferma del genitore/tutore**

   - Invio email con link sicuro per la firma.
   - Se firmato:
     - Stato: `Firmato (in attesa validazione docente)`.
   - Se non firmato:
     - Il ritardo rimane in stato `In attesa firma tutore`.
3. **Gestione del termine**

   - L’allievo ha 5 giorni (esclusi i weekend) per giustificare il ritardo.
   - Il sistema invia una notifica prima della scadenza.
4. **Validazione del docente di classe**

   - Se la giustificazione è valida:
     - Stato: `Giustificato`.
   - Se il termine scade senza giustificazione:
     - Stato: `Arbitrario`.
     - Notifica a studente e docente.
     

## Flusso 4 - Report mensile assenze, ritardi e congedi

**Attori:** Sistema, Allievo, Genitore/Tutore, Docente di classe

### 1. Generazione automatica del report

- Ogni mese il sistema genera un report per ogni allievo.
- Il report deve contenere almeno:
  - ore di assenza
  - ritardi
  - congedi
  - situazione rispetto alle 40 ore
  - eventuali penalita
  - eventuali certificati medici caricati o mancanti
- Il report viene generato in formato `PDF`.
- Il PDF deve essere ben impaginato, chiaro da leggere, stampare e archiviare.

### 2. Invio del report

- Una volta generato, il report viene inviato tramite email:
  - all'allievo
  - al genitore/tutore
- Il docente di classe puo reinviare in qualsiasi momento l'email del report senza dover creare un nuovo report manualmente.

### 3. Azioni richieste ad allievo e tutore

- Dopo aver ricevuto il report, allievo e tutore devono:
  - scaricare il PDF
  - stamparlo
  - farlo firmare dal genitore/tutore
  - scansionarlo
  - caricarlo sul sito in una sezione dedicata
- Il sistema deve quindi avere un'area apposita per il caricamento del report firmato e scansionato.

### 4. Controllo del docente di classe

- Il docente di classe puo:
  - vedere i report inviati
  - reinviare il report via email
  - controllare il PDF firmato e scansionato caricato dall'allievo
  - approvare il report scansionato
- Quando il report viene approvato:
  - viene considerato archiviato
  - puo essere lasciato da parte rispetto ai report ancora da controllare

### 5. Monitoraggio dei report mancanti

- Il docente di classe deve poter vedere facilmente:
  - quali report firmati e scansionati sono gia stati caricati
  - quali report sono ancora mancanti
  - quali report sono in attesa di approvazione
- In questo modo il docente puo concentrarsi subito sui report mancanti o non ancora approvati.

## Flusso 5 - Superamento ore massime (assenze e congedi)

**Attori:** Allievo, Docente di classe, Capo laboratorio

### Trigger

- Il sistema legge il limite annuo da database (`absence_settings.max_annual_hours`).
- Se, con una nuova assenza, il totale ore in conteggio 40 (assenze + congedi conteggiati) supera il limite, si attiva questo flusso.

### Regola certificato medico (assenze)

- Per ogni assenza **non derivata da congedo**, il certificato medico diventa obbligatorio anche se l'assenza e inferiore ai giorni minimi standard.
- Se il certificato obbligatorio non viene gestito entro i termini previsti, l'assenza puo diventare `Arbitraria`.

### Regola congedi

- Per i congedi, il comportamento resta invariato rispetto al flusso attuale.
- Nessuna modifica aggiuntiva ora: la gestione congedi verra rivista in una fase successiva.




cose future

Soluzione corretta: policy/middleware sulle route (can:view,leave, can:update,leave, ecc.) + test autorizzazione per ogni endpoint.