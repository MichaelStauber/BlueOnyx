#!/bin/sh

if [ "$1" = "clean" ]; then
  rm -rf cmake_build
  exit 0
fi

mkdir -p cmake_build
cd cmake_build

# CMake with explicit flags to disable -Werror and allow casts
cmake ../ -DCMAKE_INSTALL_PREFIX=/usr -DCMAKE_C_FLAGS="-Wno-cast-qual -Wno-error"
make
