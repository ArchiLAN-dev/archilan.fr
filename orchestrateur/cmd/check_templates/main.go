package main

import (
	"context"
	"fmt"
	"log"
	"os"
	"strings"

	"archilan.fr/orchestrateur/internal/templateparser"
	"github.com/minio/minio-go/v7"
	"github.com/minio/minio-go/v7/pkg/credentials"
)

func main() {
	mc, err := minio.New("localhost:9000", &minio.Options{
		Creds:  credentials.NewStaticV4("minioadmin", "minioadmin", ""),
		Secure: false,
	})
	if err != nil {
		log.Fatal(err)
	}

	ctx := context.Background()

	// If a key argument is given, dump raw lines for that object
	if len(os.Args) > 1 {
		key := os.Args[1]
		r, err := mc.GetObject(ctx, "apworlds", key, minio.GetObjectOptions{})
		if err != nil {
			log.Fatal(err)
		}
		data, err := readAll(r)
		r.Close()
		if err != nil {
			log.Fatal(err)
		}
		fmt.Println(string(data))
		return
	}

	for obj := range mc.ListObjects(ctx, "apworlds", minio.ListObjectsOptions{}) {
		if obj.Err != nil {
			log.Fatal(obj.Err)
		}
		if !strings.HasSuffix(obj.Key, ".yaml") {
			continue
		}

		r, err := mc.GetObject(ctx, "apworlds", obj.Key, minio.GetObjectOptions{})
		if err != nil {
			fmt.Fprintf(os.Stderr, "  get %s: %v\n", obj.Key, err)
			continue
		}
		data, err := readAll(r)
		r.Close()
		if err != nil {
			fmt.Fprintf(os.Stderr, "  read %s: %v\n", obj.Key, err)
			continue
		}

		options := templateparser.Parse(data)
		fmt.Printf("\n=== %s — %d option(s) ===\n", obj.Key, len(options))
		for _, o := range options {
			rng := ""
			if o.RangeMin != nil {
				rng = fmt.Sprintf(" [%d..%d]", *o.RangeMin, *o.RangeMax)
			}
			vals := ""
			if len(o.ValidValues) > 0 {
				vv := o.ValidValues
				if len(vv) > 5 {
					vv = append(vv[:5], "...")
				}
				vals = fmt.Sprintf(" values=%v", vv)
			}
			desc := ""
			if o.Description != "" {
				line := strings.SplitN(o.Description, "\n", 2)[0]
				if len(line) > 60 {
					line = line[:60] + "…"
				}
				desc = fmt.Sprintf(" | %s", line)
			}
			fmt.Printf("  %-40s  %-8s  default=%-20v%s%s%s\n",
				o.Key, o.Type, fmt.Sprintf("%v", o.DefaultValue), rng, vals, desc)
		}
	}
}

func readAll(r interface{ Read([]byte) (int, error) }) ([]byte, error) {
	var buf []byte
	tmp := make([]byte, 32*1024)
	for {
		n, err := r.Read(tmp)
		if n > 0 {
			buf = append(buf, tmp[:n]...)
		}
		if err != nil {
			if err.Error() == "EOF" {
				break
			}
			return nil, err
		}
	}
	return buf, nil
}
