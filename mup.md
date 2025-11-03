# Cosa fa ogni file

## `/mnt/data/test.php`

-   Bootstrap dell’ambiente: include i define di progetto, la connessione PDO e le tre classi MailUp.
    
-   Avvia la sessione, apre la connessione DB via `getPDOConnection()` e interrompe se fallisce.
    
-   È un piccolo **script di prova**: contiene (commentato) un esempio di invio SMS transazionale con `MailupTransactionalSmsService->sendTransactionalSms($accountId, $listId, $recipient, $opts)` usando:
    
    -   `content` (o in alternativa `templateId`)
        
    -   `campaignCode`
        
    -   `dynamicFields` (array di coppie `{N: name, V: value}`)
        
    -   `isUnicode`, `sender` (se abilitato su account)
        
-   Scopo: dimostrare wiring e forma del payload per un invio reale.
    

## `/mnt/data/MailupTransactionalSmsClient.php`

(la classe si chiama **`MailupTransactionalSmsService`**)

**Responsabilità principali**

1.  **Gestione ListSecret** (necessario per i transazionali):
    
    -   Cache “in-process” (static) → evita doppie letture nella stessa richiesta.
        
    -   Cache **DB** su tabella `Mailup_ListSecrets`.
        
    -   Se non è in cache/DB:
        
        -   prova **GET** `/<root>/lists/{listId}/listsecret` (Basic Auth) e, se esiste, lo salva in DB;
            
        -   se **non esiste lato MailUp**, legge da DB il `list_guid` (tabella `Mailup_Lists`) e fa **POST** per **creare** il ListSecret, poi lo persiste in `Mailup_ListSecrets`.
            
2.  **Invio SMS transazionale**:
    
    -   Metodo chiave: `sendTransactionalSms($accountId, $listId, $recipient, array $opts)`.
        
    -   Prepara payload con **Recipient**, **ListGuid**, **ListSecret**, **IsUnicode**, e **Content** _oppure_ **TemplateId**; opzionali **CampaignCode**, **DynamicFields** normalizzati e **Sender** (se abilitato).
        
    -   Endpoint: **`POST {baseSmsUrl}/{accountId}/{listId}`** (no Basic Auth qui).
        
    -   Valida la risposta con `assertMailupOk` (HTTP 200 e `Code==0`), estrae **DeliveryId** e **Cost** e li ritorna (insieme al raw di debug).
        
3.  **HTTP client robusto** (cURL):
    
    -   Forza **HTTP/1.1**, **TLS 1.2**, **IPv4**, `Expect:` vuoto e `Connection: close`.
        
    -   **Retry** su errori di rete/HTTP 5xx (backoff crescente).
        
    -   Per gli endpoint `listsecret` usa **Basic Auth** (user/pass dedicati).
        
4.  **DB access (PDO)**:
    
    -   Lettura/scrittura di `list_secret` su `Mailup_ListSecrets`.
        
    -   Lettura di `list_guid` da `Mailup_Lists`.
        
5.  **Logging & sicurezza**:
    
    -   Usa il logger del progetto se passato al costruttore (fallback a `error_log`).
        
    -   **Maschera** i campi sensibili (token, secret, password, Authorization) nei log.
        

**Configurazione**

-   `baseSmsUrl` (es. `https://sendsms.mailup.com/api/v2.0/sms`) e derivata `baseRootUrl`.
    
-   Credenziali **Basic Auth** per `/listsecret`.
    
-   Può leggere da **costanti globali** (es. `MAILUPRESTBASEURL`, `MAILUPRESTACCOUNTUSER`, `MAILUPRESPASSWORD`) se non passate via `$opts`.
    

## `/mnt/data/MailupOAuthPasswordClient.php`

Client **OAuth2 Password Grant** per il **ConsoleService** (niente redirect/PKCE, solo backend).

**Responsabilità principali**

1.  **Password Grant**: `passwordGrant()`:
    
    -   Richiede `access_token` (ed eventualmente `refresh_token`) a  
        `https://services.mailup.com/Authorization/OAuth/Token` con **Basic** (clientId:clientSecret) e form `{grant_type=password, username, password}`.
        
    -   Aggiunge `expires_at` calcolato server-side (comodo per cache locale).
        
2.  **Refresh Token**: `refresh($refreshToken)` con `{grant_type=refresh_token}`.
    
3.  **HTTP client robusto**:
    
    -   Come sopra: HTTP/1.1, TLS1.2, IPv4, disabilita proxy ereditati, retry su rete/5xx.
        
4.  **Logging & sicurezza**:
    
    -   Maschera `access_token`, `refresh_token`, `client_secret`, `password` nei log.
        

**Configurazione**

-   Legge `client_id`, `client_secret`, `username`, `password` da `$opts` o da costanti globali (es. `MAILUPRESTCLIENTID`, `MAILUPRESTCLIENTSECRET`, `MAILUPRESTACCOUNTUSER`, `MAILUPRESPASSWORD`).
    
-   Log iniziale “OAuth client ready” con i timeout/retry configurati.
    

## `/mnt/data/MailupConsoleClient.php`

Client per interrogare il **ConsoleService** (REST v1.1) per recuperare lo **stato** di un invio SMS transazionale a partire dal **DeliveryId**.

**Endpoint base**

-   `https://services.mailup.com/API/v1.1/Rest/ConsoleService.svc`
    

**Flusso di `getDeliveryStatus($listId, $deliveryId, ?$campaignCode, $daysBack)`**

1.  **Ottiene access token** ogni volta via `oauthPasswordGrant()` (usa la classe sopra).
    
2.  **Risoluzione `idMessage`**:
    
    -   Scansiona `Console/Sms/List/{listId}/Messages` in **ordine decrescente** per `idMessage`, paginando fino ai limiti fissati.
        
    -   Applica **filtro temporale obbligatorio** `CreationDate ge {sinceUtc}` dove `sinceUtc = now-{$daysBack}d` (UTC).
        
    -   Se fornito `campaignCode`, prima tenta con **Subject.Contains(campaignCode)**, poi solo filtro data, poi **nessun filtro** (fallback).
        
    -   Per ogni candidato `idMessage` fa una chiamata **leggera** a  
        `Console/Sms/{idMessage}/Sendings/ReportDetails?DeliveryId={deliveryId}`:  
        se trova righe, quello è l’`idMessage` giusto.
        
3.  **Recupero dettagli finali**:
    
    -   Richiama `ReportDetails` come sopra e ritorna `{found, listId, idMessage, rows, raw}`.
        
4.  **HTTP client**:
    
    -   GET con **Bearer** header, HTTP/1.1, TLS1.2, IPv4, `Connection: close`, retry su rete/5xx.
        
5.  **Logging**:
    
    -   Log minimale con prefisso `[MailupConsole]` su `error_log` (allineato ai tuoi log d’esempio dove si vede un 500 gestito con retry).
        

## `/mnt/data/mailuptables.sql`

-   Definisce due tabelle nel DB (InnoDB, utf8mb4):
    
    -   **`Mailup_Lists`**: anagrafica liste (**PK `list_id`**, `list_guid` univoco, `name`, `note`, …) con molte righe di esempio/pronte all’uso (p.es. la **58: “SERFIN97 – Test REST”**).
        
    -   **`Mailup_ListSecrets`**: cache del **ListSecret** per lista (**PK `list_id`**, `list_secret`, `updated_at` con `ON UPDATE CURRENT_TIMESTAMP`), con **FK** su `Mailup_Lists(list_id)` e **ON DELETE/UPDATE CASCADE**.
        
-   Il service degli SMS usa queste tabelle per:
    
    -   leggere il **`list_guid`** (da `Mailup_Lists`)
        
    -   leggere/salvare il **`list_secret`** (su `Mailup_ListSecrets`) e mantenerlo aggiornato.
        

----------

# Flusso end-to-end (riassunto)

1.  **Invio SMS transazionale**
    
    -   Input: `accountId`, `listId`, `recipient`, opzioni (`content` o `templateId`, `campaignCode`, `dynamicFields`, `sender`, `isUnicode`).
        
    -   Il service:
        
        1.  garantisce di avere un **ListSecret** valido per la lista (`ensureListSecret` con cache DB + API GET/POST);
            
        2.  legge il **ListGuid**;
            
        3.  invia **POST** a `.../sms/{accountId}/{listId}` con `ListGuid + ListSecret`;
            
        4.  valida la risposta (`Code==0`, HTTP 200) ed estrae **`DeliveryId`** (e `Cost`).
            
2.  **Verifica stato/consegna**
    
    -   Input: `listId`, **`DeliveryId`** (opzionale `campaignCode` per filtrare più velocemente) e una finestra temporale (`daysBack`).
        
    -   Il client Console:
        
        1.  fa **OAuth Password Grant** → `access_token`;
            
        2.  trova l’**`idMessage`** corretto scorrendo i messaggi della lista (con filtri progressivi e paginazione);
            
        3.  interroga **ReportDetails** filtrando per **`DeliveryId`** e restituisce le righe (esiti, timestamp, ecc.).
            

----------

# Note di allineamento progetto

-   **PDO + prepared statements**: tutta l’I/O DB è già in PDO con `prepare/execute`, coerente con le tue preferenze.
    
-   **Hardening rete/compatibilità**: forzare HTTP/1.1/TLS1.2/IPv4, disabilitare `Expect: 100-continue` e non usare keep-alive minimizza i reset e si allinea ai log che hai mostrato (dove compaiono retry e un 500 dal Console).
    
-   **Masking log**: token e secret non finiscono mai in chiaro nei log (bene).
    
-   **Costanti di configurazione**: le classi cercano prima in `$opts`, poi in define globali (`MAILUPREST*`) — utile per ambienti diversi (dev/test/prod) senza toccare codice.
