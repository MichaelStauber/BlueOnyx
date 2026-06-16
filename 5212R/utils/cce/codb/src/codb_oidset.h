#ifndef __CODB_OIDSET_H__
#define __CODB_OIDSET_H__

/*
 * Include the necessary type definitions first.
 * oid_t is defined in codb.h along with the rest of the CODB API.
 * gpointer and GHashTable come from glib.h.
 */
#include <codb.h>
#include <glib.h>

/*
 * codb_oidset: HashSet fuer oid_t zur effizienten Schnittmenge bei FIND.
 * Minimal implementation using GLib GHashTable internally.
 */

typedef struct codb_oidset_struct codb_oidset;

codb_oidset *codb_oidset_new(void);
void         codb_oidset_destroy(codb_oidset *set);
void         codb_oidset_add(codb_oidset *set, oid_t oid);
int          codb_oidset_contains(codb_oidset *set, oid_t oid);
int          codb_oidset_size(codb_oidset *set);
oid_t       *codb_oidset_to_array(codb_oidset *set, int *count);

#endif
