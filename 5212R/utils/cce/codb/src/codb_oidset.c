/* $Id: codb_oidset.c,v 1.0 2026/04/21 Exp $ */
/* Copyright 2026 BlueOnyx.  All rights reserved. */

#include <cce_common.h>
#include <codb.h>
#include <glib.h>
#include <stdlib.h>
#include "codb_oidset.h"

struct codb_oidset_struct {
    GHashTable *set;
};

codb_oidset *
codb_oidset_new(void)
{
    codb_oidset *set = malloc(sizeof(codb_oidset));
    if (!set)
        return NULL;
    set->set = g_hash_table_new(g_direct_hash, g_direct_equal);
    if (!set->set) {
        free(set);
        return NULL;
    }
    return set;
}

void
codb_oidset_destroy(codb_oidset *set)
{
    if (!set)
        return;
    g_hash_table_destroy(set->set);
    free(set);
}

void
codb_oidset_add(codb_oidset *set, oid_t oid)
{
    g_hash_table_insert(set->set, GUINT_TO_POINTER(oid), GUINT_TO_POINTER(1));
}

int
codb_oidset_contains(codb_oidset *set, oid_t oid)
{
    return g_hash_table_lookup(set->set, GUINT_TO_POINTER(oid)) != NULL;
}

int
codb_oidset_size(codb_oidset *set)
{
    return g_hash_table_size(set->set);
}

typedef struct {
    oid_t *oids;
    int count;
    int capacity;
} oid_array;

static void
collect_oid(gpointer key, gpointer val, gpointer data)
{
    oid_array *arr = (oid_array *)data;
    if (arr->count >= arr->capacity)
        return;
    arr->oids[arr->count++] = GPOINTER_TO_UINT(key);
}

oid_t *
codb_oidset_to_array(codb_oidset *set, int *count)
{
    int size = codb_oidset_size(set);
    oid_t *oids;
    oid_array arr;

    oids = malloc(sizeof(oid_t) * size);
    if (!oids) {
        *count = 0;
        return NULL;
    }
    arr.oids = oids;
    arr.count = 0;
    arr.capacity = size;
    g_hash_table_foreach(set->set, collect_oid, &arr);
    *count = arr.count;
    return oids;
}
