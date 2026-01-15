# GGPP : Generic GET PUT POST

This is a generic web server application, accessible via a simple API, to GET or PUT or POST any kind of data.

The server side is written in PHP. The data storage backend can use local file system, local SQLite database, shared MySQL. Later, it will support Postgresl database, REDIS, S3, DynamoDB, MongoDB.

Insipration has been taken from how [fiddle.js](https://jsfiddle.net/) or pastebin.com : user create text content, on save it generate a unique identifier, and any user with the identifier can retreive (and modify) the initial content.

GGPP create a unique document identifier (udi) on each new save (PUT request). Update of an existing document use the POST request (with the udi as an input), and getting an existing document use the GET request (with also the udi).

## Configuration

Have a look at the `.config.dist.php` file, and create a copy named `.config.php`.
The configuration allow to customize :
- data storage layer (file, sqlite, mysql)
- maximum size of data per document
- maximum retention period of data (2 years by default)
- a salt, used when creating the udi (if you change it, you may lost every data)
- allowed client_id to identify client usage
- for each client_id :
    - the period of time to count number of write (PUT or POST) ( ex : 60 for a rate limitation based on number of request per minute)
    - maximum number of write (PUT or POST) per document per period of time (rate limitation)
    - a flag indicating if rate limitation apply for all clients using this client_id whatever their IP, or if limitation is calculated per client IP.

## Usage

From the client side, you can request the GGPP API like this :

| Action | URL | Comment |
|-|-|-|
|retreive document | `GET /?client_id=xxx&udi=zzz`| Retreive a document. Return http 200 OK or 404 or 429 or 403 |
|put initial document | `PUT /?client_id=xxx` | The body of the PUT request will be saved. Return http 200 "OK:zzz" where zzz is the udi of the created document, or 403, or 429|
|modify existing document | `POST /?client_id=xxx&udi=zzz` | The body of the post request will replace the existing document. Return http 200 "OK:zzz" (zzz is the udi of the updated document), or 403 or 429, or 404 |

There is a live html demo availble here : `demo.html`.
The client_id can be transmitted via the http header "x-client-id" (prefered method) or the query string (?client_id=xxx in the url).

## Error handling
- 400 : Malformed request. Something is missing (the client_id or the udi or the data)
- 403 : Not authorized. The client_id is not allowed to use the API.
- 404 : The requested document does not existing. Unable to get it or to modify (POST) it.
- 405 : The http method is not valid here (you are not using GET, POST or PUT or in a wrong way)
- 413 : The data is too large to be stored
- 429 : Too many action, see bellow "rate limitation"
- 500 : A server error (can be file system full, database connection error, ...)

## Rate limitation

The configuration file allow, for each client_id, to set a maximum number of write request (PUT or POST) per period of time. For example, it is possible to limit the client_id named "webdemo" to do no more than 1 request per minutes. If this client do more than 1 request (what ever it is PUT or POST) in 1 minute, it will receive a 429 error.
Rate limitation can be set by client_id only, or by client_id and ip address of the client (anonymized).
There is currently no rate limitation on read access (GET).

## Server prerequisits :

- PHP >7
- For file system data storage backend : a writeable file system and the SQLite extension

## Data model

### Generating a new unique document identifier

On document creation, a new UDI is created. It looks like "YYM-ABC-DEF-GHI" where :
- YY is the year (26 for 2026)
- M is the month (from 1 (january) to C (december) : yes it is a base 12 digit)
- ABC-DEF-GHI is a 9 letters code, formed of uppercase letters and numbers from the following alphabet : "ABCDEFGHJKLMNPRSTUVWXYZ123456789" (32 char). There is no O, Q or 0, no I or L. This code is is not incremental and not predictable. It is randomly generated with collision detection.

At the end, the UDI can generate 31^9 combination per month.

### File

If the data storage backend is set to file, the document is stored locally, in a directory structure.
The first sub-directory is the YYM code of the UDI, then the next 3 chars of the code is used to balance data file in sub directories.
Example : for UDI "26B-CWP-R3S-9CN", the data file is stored in "26B/QWP/26B-QWP-R3S-9CN.data"

#### Implementation of the rate limitation
With the file data storage backend, this application rely on a SQLite database to track request rate of client_id.

### Database

For SQLite or MySQL data storage backend, the DB structure is made of :
- the "document" table, with column "udi" (primary unique identifier), "date_update" (the datetime of the last create or update), "data" (the binary data)
- the "client" table is used to track client request (and apply the rate limitation). It contains columns :
  - "client_id" : will be initialized at first use of this client_id
  - "time_period" : the time/date period to start the rate limitation. For example, if the period of time is 60 (limitation per minute), the "time_period" is rounded to the last minute (ex : if the access is at 12:34:56, the time_period is "2026-20-01 12:34:00")
  - "nb_request" : the number of request per time period (incremented on each request)

