/* $Id: sessionmgr.c 259 2004-01-03 06:28:40Z shibuya $ */
/* Copyright 2001 Sun Microsystems, Inc.  All rights reserved. */
/*
 * Implements session manager functionality.
 *
 * jmayer, thockin (c) Cobalt Networks.
 * 
 * Modified 2026-04-21 by Michael Stauber, SOLARSPEED.NET
 * Added optional Redis/Valkey support with automatic fallback to
 * file-based session storage. Tries TCP 127.0.0.1:6379 first,
 * then Unix sockets (/var/run/valkey/valkey.sock, /var/run/redis/redis.sock),
 * then falls back to file-based storage.
 */

#include <cce_common.h>
#include <sessionmgr.h>

#include <stdio.h>
#include <unistd.h>
#include <sys/types.h>
#include <string.h>
#include <stdlib.h>
#include <sys/stat.h>
#include <utime.h>
#include <dirent.h>
#include <time.h>
#include <fcntl.h>
#include <ctype.h>
#include <glib.h>

#ifdef USE_REDIS
#include <hiredis/hiredis.h>
#include <openssl/hmac.h>
#include <openssl/evp.h>
#include <sys/socket.h>
#include <netinet/in.h>
#include <arpa/inet.h>
#include <errno.h>
#endif

/* some constants */
#define IDLEN 63
#define NAMELEN	31
#define SESSIONDIR CCESESSIONSDIR
#define DEVRAND "/dev/urandom"

/* Redis/Valkey connection constants */
#ifdef USE_REDIS
#define REDIS_HOST "127.0.0.1"
#define REDIS_PORT 6379
#define REDIS_SOCKET_VALKEY "/var/run/valkey/valkey.sock"
#define REDIS_SOCKET_REDIS "/var/run/redis/redis.sock"
#define SESSION_SECRET_FILE "/usr/sausalito/conf/sessionmgr.secret"
#define REDIS_SESSION_KEY_PREFIX "cce:session:"
#endif

/* default value for how long sessions live */
int session_timeout = SESSION_TIMEOUT;

/* what's inside a session object */
struct cce_session_struct {
	char session_id[IDLEN+1];
	char username[NAMELEN+1];
};

static void makedirectory(); 
static int isunique(cce_session *s);
static void cce_session_save(cce_session *s);
static cce_session *cce_session_load(char *session_id);
static inline char *session_filename(char *sessid);
static inline int isalphanumeric(char c);

#ifdef USE_REDIS
static char *redis_session_key(char *session_id);
static int redis_save(cce_session *s);
static cce_session *redis_load(char *session_id);
static int redis_delete(char *session_id);
static int redis_exists(char *session_id);
static int redis_refresh(char *session_id);
#endif

/* our keyspace */
static char *alphanumeric=
	"abcdefghijklmnopqrstuvwxyz"
	"ABCDEFGHIJKLMNOPQRSTUVWXYZ"
	"0123456789";

#ifdef USE_REDIS
static int
read_session_secret(unsigned char *secret, size_t secret_size,
	size_t *secret_len)
{
	struct stat st;
	ssize_t n;
	int fd;

	*secret_len = 0;

	fd = open(SESSION_SECRET_FILE, O_RDONLY);
	if (fd < 0) {
		CCE_SYSLOG("can not access %s: %m", SESSION_SECRET_FILE);
		return -1;
	}

	if (fstat(fd, &st) != 0) {
		CCE_SYSLOG("can not stat %s: %m", SESSION_SECRET_FILE);
		close(fd);
		return -1;
	}

	if (!S_ISREG(st.st_mode) || st.st_uid != 0 || (st.st_mode & 077) != 0) {
		CCE_SYSLOG("%s must be a regular file owned by root with mode 0600",
		    SESSION_SECRET_FILE);
		close(fd);
		return -1;
	}

	n = read(fd, secret, secret_size);
	close(fd);
	if (n <= 0) {
		CCE_SYSLOG("%s is empty or unreadable", SESSION_SECRET_FILE);
		return -1;
	}

	*secret_len = (size_t)n;
	while (*secret_len > 0
	    && isspace((unsigned char)secret[*secret_len - 1])) {
		(*secret_len)--;
	}
	if (*secret_len == 0) {
		CCE_SYSLOG("%s contains no usable secret", SESSION_SECRET_FILE);
		return -1;
	}

	return 0;
}

static char *
redis_session_key(char *session_id)
{
	unsigned char secret[4096];
	unsigned char digest[EVP_MAX_MD_SIZE];
	unsigned int digest_len = 0;
	size_t secret_len;
	size_t prefix_len;
	char *key;
	unsigned int i;

	if (!session_id || !session_id[0]) {
		return NULL;
	}

	if (read_session_secret(secret, sizeof(secret), &secret_len) != 0) {
		return NULL;
	}

	if (!HMAC(EVP_sha256(), secret, (int)secret_len,
	    (const unsigned char *)session_id, strlen(session_id),
	    digest, &digest_len)) {
		CCE_SYSLOG("failed to derive Redis session key");
		return NULL;
	}

	prefix_len = strlen(REDIS_SESSION_KEY_PREFIX);
	key = malloc(prefix_len + (digest_len * 2) + 1);
	if (!key) {
		return NULL;
	}

	strcpy(key, REDIS_SESSION_KEY_PREFIX);
	for (i = 0; i < digest_len; i++) {
		sprintf(key + prefix_len + (i * 2), "%02x", digest[i]);
	}
	key[prefix_len + (digest_len * 2)] = '\0';

	return key;
}

/*
 * Simple Redis helper: connect, try TCP then Unix sockets.
 * No persistent connections. Each call opens and closes.
 * Fork-safe, thread-safe.
 */
static redisContext *
redis_connect_once(void)
{
	struct timeval timeout = { 1, 0 }; /* 1 second */
	redisContext *c = NULL;

	/* Try TCP first */
	c = redisConnectWithTimeout(REDIS_HOST, REDIS_PORT, timeout);
	if (c != NULL && !c->err) {
		return c;
	}
	if (c) {
		redisFree(c);
	}

	/* Try Valkey Unix socket */
	c = redisConnectUnixWithTimeout(REDIS_SOCKET_VALKEY, timeout);
	if (c != NULL && !c->err) {
		return c;
	}
	if (c) {
		redisFree(c);
	}

	/* Try Redis Unix socket */
	c = redisConnectUnixWithTimeout(REDIS_SOCKET_REDIS, timeout);
	if (c != NULL && !c->err) {
		return c;
	}
	if (c) {
		redisFree(c);
	}

	return NULL;
}

static int
redis_save(cce_session *s)
{
	redisContext *c;
	redisReply *reply;
	char *redis_key;
	int r = -1;

	redis_key = redis_session_key(s->session_id);
	if (!redis_key) return -1;

	c = redis_connect_once();
	if (!c) {
		free(redis_key);
		return -1;
	}

	reply = redisCommand(c, "SETEX %s %d %s",
				     redis_key, session_timeout, s->username);
	if (reply) {
		if (reply->type == REDIS_REPLY_STRING
		    || reply->type == REDIS_REPLY_STATUS) {
			if (strcasecmp(reply->str, "OK") == 0) {
				r = 0;
			}
		}
		freeReplyObject(reply);
	}
	reply = redisCommand(c, "DEL cce:session:%s", s->session_id);
	if (reply) {
		freeReplyObject(reply);
	}
	redisFree(c);
	free(redis_key);
	return r;
}

static cce_session *
redis_load(char *session_id)
{
	redisContext *c;
	redisReply *reply;
	char *redis_key;
	cce_session *s = NULL;

	if (!session_id || !session_id[0]) return NULL;

	redis_key = redis_session_key(session_id);
	if (!redis_key) return NULL;

	c = redis_connect_once();
	if (!c) {
		free(redis_key);
		return NULL;
	}

	reply = redisCommand(c, "GET %s", redis_key);
	if (reply) {
		if (reply->type == REDIS_REPLY_STRING && reply->str) {
			s = (cce_session *)malloc(sizeof(cce_session));
			if (s) {
				strncpy(s->username, reply->str, NAMELEN);
				s->username[NAMELEN] = '\0';
				strncpy(s->session_id, session_id, IDLEN);
				s->session_id[IDLEN] = '\0';
			}
		}
		freeReplyObject(reply);
	}
	redisFree(c);
	free(redis_key);
	return s;
}

static int
redis_delete(char *session_id)
{
	redisContext *c;
	redisReply *reply;
	char *redis_key;
	int r = -1;

	redis_key = redis_session_key(session_id);
	if (!redis_key) return -1;

	c = redis_connect_once();
	if (!c) {
		free(redis_key);
		return -1;
	}

	reply = redisCommand(c, "DEL %s", redis_key);
	if (reply) {
		r = 0;
		freeReplyObject(reply);
	}
	reply = redisCommand(c, "DEL cce:session:%s", session_id);
	if (reply) {
		freeReplyObject(reply);
	}
	redisFree(c);
	free(redis_key);
	return r;
}

static int
redis_exists(char *session_id)
{
	redisContext *c;
	redisReply *reply;
	char *redis_key;
	int exists = 0;

	redis_key = redis_session_key(session_id);
	if (!redis_key) return 0;

	c = redis_connect_once();
	if (!c) {
		free(redis_key);
		return 0;
	}

	reply = redisCommand(c, "EXISTS %s", redis_key);
	if (reply) {
		if (reply->type == REDIS_REPLY_INTEGER && reply->integer == 1) {
			exists = 1;
		}
		freeReplyObject(reply);
	}
	redisFree(c);
	free(redis_key);
	return exists;
}

static int
redis_refresh(char *session_id)
{
	redisContext *c;
	redisReply *reply;
	char *redis_key;
	int r = -1;

	redis_key = redis_session_key(session_id);
	if (!redis_key) return -1;

	c = redis_connect_once();
	if (!c) {
		free(redis_key);
		return -1;
	}

	reply = redisCommand(c, "EXPIRE %s %d",
				     redis_key, session_timeout);
	if (reply) {
		if (reply->type == REDIS_REPLY_INTEGER && reply->integer == 1) {
			r = 0;
		}
		freeReplyObject(reply);
	}
	redisFree(c);
	free(redis_key);
	return r;
}

#endif /* USE_REDIS */

/*
 * Call this in the child process after fork() to reset any
 * shared connections (like Redis). With the new connect-per-call
 * model this is essentially a no-op, but kept for safety.
 */
void
cce_sessionmgr_fork_child(void)
{
}

/* create a new session for the user */
cce_session *
cce_session_new(char *username)
{
	cce_session *s;
	int i, fd;
  
	/* make sure environment is ok */
	makedirectory();

	/* get memory */
	s = (cce_session *)malloc(sizeof(cce_session));
	if (!s) 
		return NULL;

	/* copy in username */
	strncpy(s->username, username, NAMELEN);
	s->username[NAMELEN] = '\0';

	/* get a random string for the session_id */
	fd = open(DEVRAND, O_RDONLY);
	if (fd < 0) {
		CCE_SYSLOG("can not access %s: %m", DEVRAND);
		free(s);
		return NULL;
	}
   
   	/* were we a valid user of "" (anonymous) */
	if (strcmp(username, "")) {
  	 	s->session_id[0] = '\0';
		/* make sure the sessionid is unique */
		while (!isunique(s)) {
			unsigned int seed;

			read(fd, (char *)&seed, sizeof(unsigned int));
			srandom(seed);

			for (i = 0; i < IDLEN; i++) {
				long c = random();
				s->session_id[i] = 
					alphanumeric[c % strlen(alphanumeric)];
			}
			s->session_id[IDLEN] = '\0';
		}

		/* make it valid */
		cce_session_save(s);
	} else {
		for (i = 0; i < IDLEN; i++) {
			s->session_id[i] = '0';
		}
		s->session_id[IDLEN] = '\0';
	}
  
	close(fd);

	return s;
}

/* attempt to reinstate a session */
cce_session *
cce_session_resume(char *username, char *session_id)
{
	struct stat statbuf;
	time_t age;
	char *filename;
	cce_session *s;
  
#ifdef USE_REDIS
	/* Try Redis/Valkey first */
	s = redis_load(session_id);
	if (s) {
		/* is it the right user? */
		if (strcmp(cce_session_getuser(s), username)) {
			cce_session_destroy(s);
			return NULL;
		}
		/* Refresh TTL on access */
		redis_refresh(session_id);
		filename = session_filename(session_id);
		utime(filename, NULL);
		free(filename);
		return s;
	}
#endif
	
	/* File-based fallback */
	filename = session_filename(session_id);

	/* does this session exist? */
	if (stat(filename, &statbuf)) {
		free(filename);
		return NULL;
	}	

	/* load 'er up */ 
	s = cce_session_load(session_id);
	if (!s) {
		free(filename);
		return NULL;
	}

	/* is it the right user? */
	if (strcmp(cce_session_getuser(s), username)) {
		free(filename);
		cce_session_destroy(s);
		return NULL;
	}

	/* has this session expired? */
	age = time(NULL) - statbuf.st_mtime;
	if ((age < 0) || (age > session_timeout)) {
		/* session has expired indeed! */
		cce_session_expire(s);
		cce_session_destroy(s);
		free(filename);
		return NULL;
	}

#ifdef USE_REDIS
	/* Populate the protected Redis key after a successful file fallback. */
	redis_save(s);
#endif
	
	free(filename);
	return s;
}

/* re-start the timestamp on a session */
void
cce_session_refresh(cce_session *s)
{
	char *filename;

	if (!s) {
		return;
	}
  
#ifdef USE_REDIS
	redis_refresh(s->session_id);
#endif

	filename = session_filename(cce_session_getid(s));
	/* touch file */
	utime(filename, NULL);
	
	free(filename);
}

/* destroy a session object */
void
cce_session_destroy(cce_session *s)
{
	if (s) {
		free(s);
	}
}

/* get the session_id */
char *
cce_session_getid(cce_session *s)
{
	if (s) {
		return s->session_id;
	} else {
		return "";
	}
}

/* get the username */
char *
cce_session_getuser(cce_session *s)
{
	if (s) {
		return s->username;
	} else {
		return "";
	}
}

/* end a session's valid lifespan */
int
cce_session_expire(cce_session *s)
{
	char *filename;
	int r = -1;

	if (!s) {
		return -1;
	}
  
#ifdef USE_REDIS
	r = redis_delete(s->session_id);
	/* Also delete file as fallback cleanup */
#endif

	filename = session_filename(s->session_id);
	if (unlink(filename) == 0) {
		r = 0;
	}
	free(filename);	

	return r;
}

/* cleanup old sessions */
void
cce_session_cleanup(void)
{
	DIR *dir;
	struct dirent *dirent;
	char *file = NULL;
	struct stat buf;

	dir = opendir(SESSIONDIR);
	if (!dir) {
		CCE_SYSLOG("could not open session directory %s", SESSIONDIR);
		return;
	}

	/* for each session file */
	while ((dirent = readdir(dir))) {
		int len;
		int age;

		if (dirent->d_name[0] == '.') {
			continue;    /* skip dot files */
		}

		len = strlen(dirent->d_name) + strlen(SESSIONDIR) + 2;
		file = (char *)malloc(len);
		snprintf(file, len, "%s/%s", SESSIONDIR, dirent->d_name);

		if (stat(file, &buf)) {
			continue;
		}

		age = time(NULL) - buf.st_mtime;
		if ((age < 0) || (age > session_timeout)) {
			unlink(file);
		}
		free(file);
	}
	closedir(dir);
}

/*
 * helper functions 
 */

/* write a session to disk, essentially making it valid */
static void
cce_session_save(cce_session *s)
{
	int fd;
	char *filename;

	if (!s) {
		return;
	}

#ifdef USE_REDIS
	/* Always attempt Redis/Valkey first - but if the server later
	 * becomes unreachable, we need the file as a safety net. */
	redis_save(s);
#endif

	filename = session_filename(s->session_id);

	fd = open(filename, O_RDWR | O_CREAT | O_TRUNC, 0600);
	free(filename);

	if (fd < 0) 
		return;
	write(fd, s->username, strlen(s->username));
	
	close(fd);
}

/* load a session from disk */
static cce_session *
cce_session_load(char *session_id)
{
	int fd;
	char *filename;
	cce_session *s;
	int r; 
	
	s = (cce_session *)malloc(sizeof(cce_session));
	if (!s) 
		return NULL;
	
	filename = session_filename(session_id);
	fd = open(filename, O_RDONLY, 0600);
	free(filename);
	
	if (fd < 0) 
		return NULL;

	r = read(fd, s->username, NAMELEN); 
	s->username[r] = '\0';
	strncpy(s->session_id, session_id, IDLEN);
	s->session_id[IDLEN] = '\0';

	close(fd);

	return s;
}

/* 
 * makedirectory: makes sure the appropriate directories exist and
 * have the right permissions.
 */
static void 
makedirectory()
{
	struct stat statbuf;

	if (stat(SESSIONDIR, &statbuf)) {
		mkdir(SESSIONDIR, S_IRWXU);
	}
	chmod(SESSIONDIR, S_IRWXU);
}

/* tell me if a session is unique enough */
static int 
isunique(cce_session *s)
{
	struct stat buf;
	char *filename;
	int r;
  
  	if (!s || !s->session_id[0]) {
		return 0;
	}

#ifdef USE_REDIS
	if (redis_exists(s->session_id)) {
		return 0;
	}
#endif

	filename = session_filename(s->session_id);

	/* if stat succeeds ( == 0), we fail (==0) */
	r = (stat(filename, &buf));

	free(filename);

	return r;
}

static inline char *
session_filename(char *sessid)
{
	GString *filename;
	char *r;

	if (!sessid) {
		return NULL;
	}

        /*
         * Make sure the key is in our keyspace.  i.e. reject
         * keys that looks like "../../../../tmp/file". 
         */
        for (r = sessid; *r != '\0'; r++) {
                if (! isalphanumeric(*r))
                        return NULL;
        }

	filename = g_string_sized_new(strlen(SESSIONDIR) + 1 + IDLEN);
	g_string_append(filename, SESSIONDIR);
	g_string_append_c(filename, '/');
	g_string_append(filename, sessid);

	r = filename->str;
	g_string_free(filename, 0);

	return r;
}

/* Is the character in our keyspace? */
static inline int
isalphanumeric(char c)
{
	char *p;

	for (p = alphanumeric; *p != '\0'; p++) {
		if (*p == c)
			return 1;
	}
	return 0;
}
/* Copyright (c) 2003 Sun Microsystems, Inc. All  Rights Reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met: 
 * 
 * -Redistribution of source code must retain the above copyright notice, this
 * list of conditions and the following disclaimer.
 * 
 * -Redistribution in binary form must reproduce the above copyright notice,
 * this list of conditions and the following disclaimer in the documentation
 * and/or other materials provided with the distribution. 
 *
 * Neither the name of Sun Microsystems, Inc. or the names of contributors may
 * be used to endorse or promote products derived from this software without 
 * specific prior written permission.

 * This software is provided "AS IS," without a warranty of any kind. ALL EXPRESS OR IMPLIED CONDITIONS, REPRESENTATIONS AND WARRANTIES, INCLUDING ANY IMPLIED WARRANTY OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE OR NON-INFRINGEMENT, ARE HEREBY EXCLUDED. SUN MICROSYSTEMS, INC. ("SUN") AND ITS LICENSORS SHALL NOT BE LIABLE FOR ANY DAMAGES SUFFERED BY LICENSEE AS A RESULT OF USING, MODIFYING OR DISTRIBUTING THIS SOFTWARE OR ITS DERIVATIVES. IN NO EVENT WILL SUN OR ITS LICENSORS BE LIABLE FOR ANY LOST REVENUE, PROFIT OR DATA, OR FOR DIRECT, INDIRECT, SPECIAL, CONSEQUENTIAL, INCIDENTAL OR PUNITIVE DAMAGES, HOWEVER CAUSED AND REGARDLESS OF THE THEORY OF LIABILITY, ARISING OUT OF THE USE OF OR INABILITY TO USE THIS SOFTWARE, EVEN IF SUN HAS BEEN ADVISED OF THE POSSIBILITY OF SUCH DAMAGES.
 * 
 * You acknowledge that  this software is not designed or intended for use in the design, construction, operation or maintenance of any nuclear facility.
 */
