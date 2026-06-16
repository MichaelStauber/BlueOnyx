#include <sys/types.h>
#include <sys/stat.h>
#include <fcntl.h>
#include <db.h>
#include <ctype.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>

static int
hexval(int c)
{
	if (c >= '0' && c <= '9') return c - '0';
	if (c >= 'a' && c <= 'f') return c - 'a' + 10;
	if (c >= 'A' && c <= 'F') return c - 'A' + 10;
	return -1;
}

static unsigned char *
hexdecode(const char *s, size_t *outlen)
{
	size_t len = strlen(s);
	unsigned char *buf;
	size_t i, j;

	buf = calloc(1, (len / 2) + 1);
	if (!buf) return NULL;

	for (i = 0, j = 0; i < len; ) {
		while (i < len && isspace((unsigned char)s[i])) i++;
		if (i >= len) break;
		int hi = hexval((unsigned char)s[i++]);
		while (i < len && isspace((unsigned char)s[i])) i++;
		if (i >= len) {
			free(buf);
			return NULL;
		}
		int lo = hexval((unsigned char)s[i++]);
		if (hi < 0 || lo < 0) {
			free(buf);
			return NULL;
		}
		buf[j++] = (unsigned char)((hi << 4) | lo);
	}

	*outlen = j;
	return buf;
}

static void
usage(const char *prog)
{
	fprintf(stderr, "usage: %s <dumpfile> <dbfile>\n", prog);
	exit(2);
}

int
main(int argc, char **argv)
{
	const char *dumpfile, *dbfile;
	FILE *fp;
	char line[8192];
	int inbody = 0;
	int expect_key = 1;
	unsigned char *keybuf = NULL, *databuf = NULL;
	size_t keylen = 0, datalen = 0;
	DB *dbp;
	BTREEINFO btinfo;
	DBT key, data;
	int rc;

	if (argc != 3) usage(argv[0]);
	dumpfile = argv[1];
	dbfile = argv[2];

	fp = fopen(dumpfile, "r");
	if (!fp) {
		perror(dumpfile);
		return 1;
	}

	unlink(dbfile);

	memset(&btinfo, 0, sizeof(btinfo));
	btinfo.flags = R_DUP;
	dbp = dbopen((char *)dbfile, O_CREAT | O_RDWR, 0600, DB_BTREE, &btinfo);
	if (!dbp) {
		perror("dbopen");
		fclose(fp);
		return 1;
	}

	while (fgets(line, sizeof(line), fp)) {
		line[strcspn(line, "\r\n")] = '\0';
		if (!inbody) {
			if (!strcmp(line, "HEADER=END")) inbody = 1;
			continue;
		}
		if (!*line) continue;
		if (expect_key) {
			free(keybuf);
			keybuf = hexdecode(line, &keylen);
			if (!keybuf) {
				fprintf(stderr, "invalid hex key line: %s\n", line);
				goto fail;
			}
			expect_key = 0;
		} else {
			free(databuf);
			databuf = hexdecode(line, &datalen);
			if (!databuf) {
				fprintf(stderr, "invalid hex data line: %s\n", line);
				goto fail;
			}
			memset(&key, 0, sizeof(key));
			memset(&data, 0, sizeof(data));
			key.data = keybuf;
			key.size = keylen;
			data.data = databuf;
			data.size = datalen;
			rc = dbp->put(dbp, &key, &data, 0);
			if (rc != 0) {
				fprintf(stderr, "db put failed: %d\n", rc);
				goto fail;
			}
			expect_key = 1;
		}
	}

	if (!expect_key) {
		fprintf(stderr, "odd number of body lines\n");
		goto fail;
	}

	free(keybuf);
	free(databuf);
	dbp->close(dbp);
	fclose(fp);
	return 0;

fail:
	free(keybuf);
	free(databuf);
	dbp->close(dbp);
	fclose(fp);
	return 1;
}

