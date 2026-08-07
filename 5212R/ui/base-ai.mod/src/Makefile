# $Id: Makefile

ifdef TOPDIR
include $(TOPDIR)/devel/defines.mk
else
include /usr/sausalito/devel/defines.mk
endif

# Full build:
#DIRS = base-ai-knowledge base-ai-core base-ai-llama base-ai-model-SmolLM2

# For faster builds skip building the LLM model:
#DIRS = base-ai-knowledge base-ai-core base-ai-llama

# For minimal builds skip building the llama and the LLM model:
DIRS = base-ai-knowledge base-ai-core

# Just llama.cpp:
#DIRS = base-ai-llama

all:
	for t in $(DIRS) ; do \
		make -C $$t all ; \
	done

install: all
	for t in $(DIRS) ; do \
		make -C $$t install ; \
	done

rpm: 
	for t in $(DIRS) ; do \
		make -C $$t rpm; \
	done

clean:
	for t in $(DIRS) ; do \
		make -C $$t clean ; \
	done
