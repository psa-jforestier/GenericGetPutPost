# GGPP : Generic GET PUT POST

This is a generic web server application, accessible via a simple API, to GET or PUT or POST any kind of data.

The server side is written in PHP. The data storage backend can use local file system, local SQLite database, shared MySQL. Later, it will support Postgresl database, REDIS, S3, DynamoDB, MongoDB.

Insipration has been taken from how [fiddle.js](https://jsfiddle.net/) or pastebin.com : user create text content, on save it generate a unique identifier, and any user with the identifier can retreive (and modify) the initial content.

GGPP create a unique document identifier (udi) on each new save (PUT request). Update of an existing document use the POST request (with the udi as an input), and getting an existing document use the GET request (with also the udi).

## Configuration

Have a look at the `.config.dist.php` file, and create a copy named `.config.php`.
The configuration allow to customize :
- data storage layer (file, sqlite, mysql)
- maximum retention period of data (2 years by default)
- a salt, used when creating the udi (if you change it, you may lost every data)
- allowed client_id to identify client usage
- for each client_id :
    - maximum size of data per document
    - the period of time to count number of write (PUT or POST) ( ex : 60 for a rate limitation based on number of request per minute)
    - maximum number of write (PUT or POST) per document per period of time (rate limitation)
    - a flag indicating if rate limitation apply for all clients using this client_id whatever their IP, or if limitation is calculated per client IP.
    - important note : the client_id identify a set (multiple) users, or all users froma an organization. It can be shared between several clients/browsers, it is not a confidential key like a credential. Anyone knowing the client_id can access (read or write) to all the data of this client_id.

## Usage

From the client side, you can request the GGPP API like this :

| Action | URL | Comment |
|-|-|-|
|retreive document | `GET /?client_id=xxx&udi=zzz`| Retreive a document. Return http 200 OK or 404 or 429 or 403 |
|put initial document | `PUT /?client_id=xxx` | The body of the PUT request will be saved. Return http 200 "OK:zzz" where zzz is the udi of the created document, or 403, or 429|
|modify existing document | `POST /?client_id=xxx&udi=zzz` | The body of the post request will replace the existing document. Return http 200 "OK:zzz" (zzz is the udi of the updated document), or 403 or 429, or 404 |

There is a live html demo availble here : `demo.html`.
The client_id can be transmitted via the http header "x-client-id" (prefered method) or the query string (?client_id=xxx in the url).

### Command line interface
For statistics and maintenance, there is the "ggpp-cli.php" script to :
- count number of document
- get disk usage
- purge old document

To see the full actions of this cli tool, run `ggpp-cli.php --help`.


## Error handling
- 400 : Malformed request. Something is missing (the client_id or the udi or the data)
- 403 : Not authorized. The client_id is not allowed to use the API.
- 404 : The requested document does not existing. Unable to get it or to modify (POST) it.
- 405 : The http method is not valid here (you are not using GET, POST or PUT or in a wrong way)
- 413 : The data is too large to be stored
- 429 : Too many action, see bellow "rate limitation"
- 500 : A server error (can be file system full, database connection error, ...)

## Rate limitation

The configuration file allow, for each client_id, to set a maximum number of request (GET, PUT or POST) per period of time. For example, it is possible to limit the client_id named "webdemo" to do no more than 1 request per minutes. If this client do more than 1 request (what ever it is GET, PUT or POST) in 1 minute, it will receive a 429 error.
Rate limitation can be set by client_id only, or by client_id and ip address of the client (anonymized).
There is currently no rate limitation differenciation between read access (GET) and write access (PUT/POST).

## Server prerequisits :

- PHP >= 7
- For file system data storage backend : a writeable file system 
- For SQLite backend : the SQLite extension in PHP + PDO
- For MYSql backend : the MySQL extension in PHP + PDO

## Data security

The data stored by GGPP are meant to be public. Do not store any confidential or personal data in it. There is no strong data security layer in this application, except the client_id (which is not a credential and is supposed to be public).
Data stored in the backed are, by default, unencrypted. If someone stole your hard drives, he can read information in it.
If you want to have better security, you should rely on your hosting provider to activate "encryption at rest and in transit" for file system and database. In this case, if someone stole the server hard drive, he will not be able to decrypt data. But if he gains root (or even user) access to the server operating system, the OS will decrypt data on-the-fly.
If you want to have better-better security, encrypt your data before PUTting then in GGPT. So if someone have root access to your server, he will be able to see the file structure, but not the data content.

## Data model

### Generating a new unique document identifier

On document creation, a new UDI is created. It looks like "YYM-ABC-DEF-GHI" where :
- YY is the year (26 for 2026)
- M is the month (from 1 (january) to C (december) : yes it is a base 12 digit)
- ABC-DEF-GHI is a 9 letters code, formed of uppercase letters and numbers from the following alphabet : "ABCDEFGHJKLMNPRSTUVWXYZ123456789" (32 char). There is no O, Q or 0, no I or L. This code is is not incremental and not predictable. It is randomly generated with collision detection.

At the end, the UDI can generate 32^9 combination per month.

### File

If the data storage backend is set to file, the document is stored locally, in a directory structure.
The first sub-directory is the YYM code of the UDI, then the next 3 chars of the code is used to balance data file in sub directories.
Example : for UDI "26B-CWP-R3S-9CN", the data file is stored in "26B/CWP/26B-CWP-R3S-9CN.data"

#### Implementation of the rate limitation
With the file data storage backend, this application rely on a SQLite database to track request rate of client_id.

### Database

For SQLite or MySQL data storage backend, the DB structure is made of :
- the "document" table, with column "udi" (primary unique identifier), "date_update" (the datetime of the last create or update), "data" (the binary data)
- the "rate_limit" table is used to track client request (and apply the rate limitation). It contains columns :
  - "client_rate_key" : will be initialized at first use of this client_id (and eventually the ip)
  - "rounded_time" : the time/date period to start the rate limitation. For example, if the period of time is 60 (limitation per minute), the "time_period" is rounded to the last minute (ex : if the access is at 12:34:56, the time_period is "2026-20-01 12:34:00")
  - "count" : the number of request per time period (incremented on each request)

#### SQLite vs file storage

SQLite is a locale file database, so what are the pros and cons of SQLite data storage vs the file data storage ?
- With SQLite, all data (content, and rate limitation counters) are in the same file, the huge ".sqlite" file
- The rate limitation system, when using file storage, is based on file system "modification time" meta data. Depending of the file system (ntfs, exfat, ext3...) the system may handle differently the modification time attribute. With SQLite, all the information about time tracking for rate limitation is stored in the SQLite file, in a single place. It will work whatever the underlying file system is.
- File system storage is very fast, because very low level. The storage structure has been designed to handle million of file in a nested tree structure, and file access can be heavly paralellized by the operating system. With the SQLite backend, all data access are made to the same .sqlite file, this can give a kind of bottleneck if million of access are in parallel
- There is no real difference in term of storage space. The single .sqlite may become huge (all data in it), but the local file storage may consume a lot of inode (one per data file).
- The SQLite backend can be easily backuped (just backup and restore the .sqlite file), but be sure to stop the application when doing backup and restore to avoid data corruption. With the file storage, do a full tgz of the data folder, and restore it when needed.
- If for any reason you want to view the data, the file system backend give you fast acces, you can grep content in anyfile and find the related UDI. With SQLite, it is more complicated, you have to open the .sqlite file inside a SQLite browser.



