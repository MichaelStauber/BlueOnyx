# Auto-detect CCE module root (this file lives at the cce tree top).
CCE_TOPDIR := $(abspath $(dir $(lastword $(MAKEFILE_LIST))))
CCE_V_MAJ=0
CCE_V_MIN=99
CCE_V_PATCH=6
CCE_VERSION=0.99.6
include $(CCE_TOPDIR)/rules.mk
CFLAGS += -fPIC
