<?php

namespace Tests\Unit\Media;

use App\Media\MediaInfo;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MediaInfoTest extends TestCase
{
    #[DataProvider('probe_output_provider')]
    public function test_probe_file(string $compressedOutput): void
    {
        $probeOutput = gzdecode(base64_decode($compressedOutput, true));
        if ($probeOutput === false) {
            throw new \LogicException('Malformed test input data');
        }

        Process::preventStrayProcesses();

        Process::fake([
            "'/usr/bin/ffprobe' *" => $probeOutput,
        ]);

        $mediaInfo = MediaInfo::probeFile('dummy_input_file');

        Process::assertRanTimes(function ($process): bool {
            return head($process->command) === '/usr/bin/ffprobe' && last($process->command) === 'dummy_input_file';
        }, 1);

        $this->assertInstanceOf(MediaInfo::class, $mediaInfo);

        // In an ideal world, we'd return the MediaInfo instance as the start of a
        // dependency chain through the rest of the tests. Sadly, PHPUnit doesn't
        // support using the output of tests using a data provider as the input to
        // another test, so we need to use the data provider on each test.

        $decodedOutput = json_decode($probeOutput, true, flags: JSON_THROW_ON_ERROR);

        $expectedTitle = $decodedOutput['format']['tags']['title'] ?? null;
        $this->assertSame($expectedTitle, $mediaInfo->title);
    }

    #[DataProvider('probe_output_provider')]
    public function test_array_ping_pong(string $compressedOutput): void
    {
        $probeOutput = gzdecode(base64_decode($compressedOutput, true));
        $decodedOutput = json_decode($probeOutput, true, flags: JSON_THROW_ON_ERROR);
        $mediaInfo = MediaInfo::fromProbeOutput($decodedOutput);

        $asArray = $mediaInfo->toArray();
        $this->assertIsArray($asArray);
        $this->assertSameSize($mediaInfo->streams, $asArray['streams']);
        $this->assertNotContains(null, $asArray);

        if ($mediaInfo->title !== null) {
            $this->assertArrayHasKey('title', $asArray);
            $this->assertSame($mediaInfo->title, $asArray['title']);
        } else {
            $this->assertArrayNotHasKey('title', $asArray);
        }

        $asMediaInfo = MediaInfo::fromArray($asArray);
        $this->assertInstanceOf(MediaInfo::class, $asMediaInfo);

        $this->assertEquals($mediaInfo, $asMediaInfo);

        $asArrayAgain = $asMediaInfo->toArray();
        $this->assertEquals($asArray, $asArrayAgain);
    }

    public static function probe_output_provider(): array
    {
        // Steps:
        //   ffprobe -loglevel +repeat+level+warning -hide_banner -output_format json=compact=0 -show_format -show_streams -show_error -show_entries stream_side_data=rotation path/to/file.mp4 > tests/ffprobe/mp4.txt
        //   vim tests/ffprobe/mp4.txt # Remove any identifying information
        //   for i in tests/ffprobe/*.txt; do echo -n "'$i' => ['"; gzip -c "$i" | base64 -w0 -; echo "'],"; done
        return [
            'avi:1' => ['H4sICO7oKWgAA2F2aS50eHQA7Vc9b9swEN3zKwRPLdA0+nJqZyvaouhQIFNQIAiIs3SSiFAUS9Ku0yL/vUfJcWxKTAJ06RDBg6z3eOI7Ht9Rf04iumbGaoTWzC6i6/6Bu/7s73oKlyVuiRC/O35edCUWTEKLBM5ahXU+m6SITtZ73vfLL19P80iBtlE6zbd3qqdueIldgAI1o6lzWTvi5x/zOMhzhHibxdl8vshHM/zFS9sQJUt9eQ3yurEEpfmU8pKFhw74UwFEZ4hRgLK8k2YiuRUXLas1cDkBNmDYilWaUurGJh5soFUCGRiFhWUa6B0uCclF4qsvuVEC7kbU/CLzqYpvWdU6ObO79SZPY+UzBG5QEH66XPpyG921QHVQuBc4RUSurB9AYzUl5+eaagU1G2S5wRUIg2Mtmy1TUNxiGeToIWlOaB8omZ+NcgKb+gUsy4mwAjMQzpK5TzCWZs2UnVrcAXMh+uJ8H/fXSNC6Xw/J+hh5tvDDPBBckHSZBsKsuN0ryeZ5mo72gDyopZl7j0/ArdVQggVm+G8XKMsnCqkzfDebYwMZCFjBWthxMnZKVgGk07zmEkQALrq2RRkKK+40LyYWoAdvQUN3iwG06nTRF9IU2CA462G8VcB1kLbhZg3iOVYhECTDqqIdGJorWAtFQ46heBGguGoqmW3W7UoCF6FIkuqp5FijDUYKu1IPl2gKzZ+ktGj7cglGUEg9JbhsxnLh0gZ1aHVaKiVOxoXaEY7w+/2/+8eBgY7mO81xR1NFy0xyLkYuMtHVLj99jwyvJZZRcn5KWy4S3FqBpySUg3y6z8G65C/qc9fJzXW8+z3T78gIRp616ws7FydpAcLe95I4Hfla0YCUKKaMmmQbpg6dOjl/52eePIL2hIKyHET5puZbdHw2sqOxQ0+QfIOe0vLvHn1orsmH83zMODLXLE2W2YTTv7rnq3vuKf+Je/Z3N8Nwt6At2KPidEdUfPA/i8a+hw0/qGxX+Y9fF+kxoHRHh9vWS8HjEDr6dmvlw8Ms9qbrvW+HHvnyx6tv0ZuPzl+jK/c1EX2TdJSkmtlg+fZw8LPb/fnT1mx3OJoly3yREeUQOzSKeZZ9WCwOUUrHCpkpOt27Zjwsyv3J/clfnoyKQKcNAAA='],
            'mkv:1' => ['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'],
            'mp4:1' => ['H4sICErpKWgAA21wNC50eHQA7VjbbuM2EH3PV6h6aoHEuviawM0iTdLdAGk3xXoDtJuFQIuUxI0uBEk5cRf59w4p2bFFyU7bPMZwAltzOBzO7Qz9/cCCly0kJygT9on1RT9Qr+/rTxpCc0weAeAebj8PC0zCIEcZAaGd+KOB3YpIizxewz70AGc51tntOfz/7eby/dFg+wtDXFqe29TFeBHRtNJB46R9K7lkGrGgmBQdEBQHcGiaxwqIFqHXiVMA97Hvjfrj0ciAPVAsE4B4x37TMwkBC6WSuZM2r+Fgx+IKsFNFWgiAhIhJWuSiJTTgqSyIOaJ5izBBIpgHEYeItK0VKGMpCZBgJJQBR7CHcoR3YngAU8FStDSho5NjI3z0MYgydSB7WS4GvsuaiJQsSArygemQtOCgPY91bOXCDJgCCIZCDZjLsWvsX2EkaBER4XtgjNMMcard04FLeJEhSO1QHVp5GeyPZBMWUZLioOC42hJyOOZECLogTSQnkdrNazymIoAU1cfmpbEoR2mQkjyWSSDo3/rwRg1SXKWxET1epYAKm17pDx0DgxbxC1CSAmCOhAZ4jj80ildIKOqAydZ80zKlQxvac/XLyLVSp1ceaB2e7/pGWawg2g0TrwegFkVzKteH8Ybu0B+Mhi0YETCiku4hqOpBwSeG/zfKyNY2NRHkEVIOI4lWARo0I6yKqBC0Nny79VYAEqEylWZy1Ieemz7VkoLTmEKGdIjDIstILjuk6ZLTsCVaWniPOCruSYc0KnhIcIcwIUi13oBmDFHeCVtQUUJu70GFKUF5QKIIuk+XrUhKFCbQLRkNOyAq9XAgkzKb54imXZpySD5MSUxkp6bujqzFmIiQ052QjEidLp0aGAE27gybkDRVbkNxV3QySCUKTVs3JHdL/tQsaxSL9pQMYWaoirGuWkh9/8j14D3z/JP++KTv1XX8l92WXdDLy8pGG9pXGyRBOU6hAldzw110qzj9Q/W4bcUCPAO9u+p3X9yv63cbmOSKZvl6cNl2xPrbhks6hqJmOW8PRQiFL5iJzs7OrR/P8ALlUDjWWYlpYZ0XGOrkpx1T0PX57hkIKT0vmYEyNkB7ZqCR1x+M3RE2+no1K9TEHqXSYPUaseq4g4FntuQQgp0TXXh+uyiAjC1KvYmQhJuz3bpjr7t1kx1oDj0WegpDGFcHNxA1U/r7mNJ1jCOYRNkCavBkqy9emyl9z/fd/rDpV4MrJ2Pgr51ceey3bLbFgL479vt7GdAw5Y0A3whwDXkjwBpiEuCnoszxfyLALnbTn75WqlTaZkhunVfdI8nKAEmE7AFXbGytyv/5BwR/W6AvO5XEbVsC99OiZE1xZcX61FmxOIQ9D7MBOuzHDP78w+zbZoderdhi1D9KGt7PIDbqZ4WPt5vwvU10f2+0V5etY288noyGk9GmcPuC4Q8nY3fzgqEofE4CERac6Ov95ulbM87O0DeI6xzurrjma4OiMpoDZEG4qA0fegYGGh6Dg82BjrUu0aXsf2Q31C+maD2DmPRGkADFObSaavoyEIRRAcNHIAouWwEJDqqfd9qEdFbmEPArJZy+e8xSq/bJz3c22HxnW3rygw4MDz7Pfj2a3NnvTqc/XHw8n/15c2mxlApp3Xz+5frq3LqzjxznjME8AeNYxkqYPBznYnZh3VxffZpZoM9xLn8HnYBMpGQnjvPw8NBDakUPvK2wwrnhBQwmcnkNmo9gTQ9LfGefTqutGuadTjEN5en0nixPhVQT3NRRn6fVuLZ+Vn/VuBAJWaMQ52i5qUMVxLaG57VOBXPqRQqEgWxCWXDxSvog13EZklfTp0L3ry18XrRTN1ATIfkDp/J17F191XE+tRtd9+ng6eAfEeExsoMVAAA='],
            'mp4:2' => ['H4sICAITKmgAA21wNF8yLnR4dADtWEtv2zgQvudXCDp1AcfRw5bt3IxgsT202F2gyKUoiLFISWz0AkkpyRb57zukascWKSf7OnQRwQ4kz6chOY9vZvLtwsPLl0owqKR/7X02P+jr2+HOQHhN2QMCgtnp72lDWUpqqBgKfYDUdwLKps4PqO32xnu3pT3UKaPetqO88W4ayuv8p/HbrWgyXpq3Pty4VavHdlhb65mAQE7wjLiABlbtAiZxGhA8JGG8WAUJHcMkVG3JSFYpjctK1U4gBCizqcU6CAJrsQLqmpXa3pFbREp4bDqziFRMMOtcO64kaZkgw3oOx/CaKw4laYHS4eAWgg6HDcfKBckEuupwiODKOgL0+csgxVG+A2nk4ZXTFlKBUKRV0rHBQaa1mAXmgbnGGmiHW+BNTYyOdbherqL1WNUeZHayCpbzOHHpQrMeDhRGq9VmM0bUu+Hcei0f5bGFYA9KAAUFRPI/mMPHlMu2kfz7dk7zbACwDLpSez+cOaTdzraVkTSC57yGckKcNlXFajUhLR8FTx1eMMI7ENDcOcLMSLNGYCJPCAsGOvEIr1rgYhLWc9lhtL6ASksGNWFZxlJXxBgMKAVpwShpeToB0SFFiSq6alcDL6c01RhUlLOcqUlNKbTaj1MaKJOp4GchFVMmWiY1tAypd9JtUvFSmw3yKe9UGEoc+YQJDTiRP40TFnLpDskS6rwb1vAZsonL0VDTEhlpz/I3jWDeR0Y5DBTveqfHszWCDEz0Ofhy+Pin+zw8He14okCNUmZUoIooWbyiQr2fI8678ra3N/j3428//3K5OH1okZq80CKQo3r1nufF+YrVc2ozu6tiQZ9aJD2qWHGYxKsksWD3nKpCm2UTjTmxYLhDwzKBxZdaOyVnXh4AZ1WUjUTIdIb4aKmK5AJ47RAWIMkR11q1YaizIFukAmLI3ZD2tWUBTbcY/jY0uba4u+UP++L+2PWLKLDqe8l6pgl2YRukxEgWmCfGt6q3HaYBsoXUAHZqFVjrDxisH7XMTL6eg7UC017woRS5cYVoKsDQTg/Fr2SZGsMyzkpKGkGHJTGGc8Gk5D2zOgOWSUeOcUkwRM2xRWe9hBWJlEgaqthXRN/KwX0vEr3Ui0TLK8vDdjPiQo26kRCbhP+8G4nCdbROgvU/70awr8HLstuhDxRw/9wL+uuzbcsiSmJLk9W2LJdvfctb3/I/6lu6ejzPGciZvuXWUaDNO9N9y4TXdLk0tBFc7z9/v8WxhsaTrsI45RVNhapSa74dj8GLJE7oapKt439lcjRMfLUMLe7/8Rj7hGatCmRPhtaY+nqKdSbdG8W+UexB/kOMhpMU+1dZ09x9GRTpsKtAnexXTxtsvwHFpJpX7TGx6dx9/j9ofCowLfEgCVyv4BTTdO1YPOzicOqq6We45qxawCzOW/xGs+rrMevt3ziZRX/veHr3Ca2hh89fb4/hLxLcK3jL3/fk4WoVr+PlcnPM6qc9aByFUbQ8FqNhdozIFB1qpsDj4ztDxq/gK1bOHY44pohw2VRjnqx4jZCeCfl9547agIzV4sl2OAMaXXKvDL+RnpfR0o4RQA398SdzM65M5a6rCFqUSzMBbvWztx2ex1Re69g049IH6LPleh5t5qGjuDxnuVm34NIzY7+HN7BrOuU593LHHu9xIBvqSHAZRpdREK78Ubg/XTxd/AkxbEC0wxcAAA=='],
            'ram:1' => ['H4sICH7pKWgAA3JhbS50eHQA7VfNbuM2EL7nKQiddoHG1q8jp6dtgg2KdNvuJsilKAhKHNlEJEogaW/cRR6t6CPtK3QoOY4tiXZ7ag8rHyxpvvnl8BvqyxnBy9NGAau0d0l+a1/Y68vuroUIyeEJAf53h+/zmkNOJasAhfhUP3qjiLKWix3sCmFkSuwfKLy5gfyxJm8+ASvfrbioyU34dtyM2TStBWZhDghbUExIyMXxiBBnAf7TLJsV+Iv6MM2qpgRaVMbiitI0DoRipg0qjgPfHzhbMimhtMUNxkW0ZJt61TqpajnIKhNG0wYU7byNrIGQwghW0oZx3qXdRyhaKKz9LlJ/OoiTrRenQUagPGO6lQdTTHcA0YYpQxujR8LoZNZIa3/it1ffAl9hBKKWtLURzcIwnDsg1gwCJogYMYSV2yUzi/10sMLwZBTjzDCqxR8Wlvb9CN3UWmxdHW6JDgAFW5VmmOs2zMwhqZVYCMlKhzivqwqky2y5USIfqW8rfGSK4bZySIta5cAdwiUwu22oqBomlBO2FnqF3XYClZfAJIWigHysF1oMM4blS+C0EbkDYpuFU7NcVZlkonRZktguXMACjNNSzhq7ji4LHHSuxFFIBabtFqeFBpAlncumjSht2djCtToVtpJANgBlAQfy593T86uig6MHPHPA0WodDUlqyNGWjB8Eh5pEEwf+hYzXFvZPyPjTg8v1jowjP4qSWRL2YZ8FN0tLB2GfVJYgFktb8zAeG06culU7+TEDZa0R4W4drxBlRReKCTkiXDJNs45Vx/i/EU8v02WzWsehPxgwJazBcsT5vM+ACooxk32in6ehn1xMo/Bilp5m/GPo/4T6g9l8dpT6gwkiTlB/mCbzYHaS+4PwG/l/I///O/m3d7936nZBK2YOmtMSErxwuAFtJrjD93rfkxl9Pe6Hh4JG1UhlVa8ErypIdPWq6Yu7KF7ny4G7rXAwWj4AF2wfeJIgTh/6vO1O9oIkTeMLf3+IHDBClPhpGOxLMfMMqM5r1VKBv58fziY9IADvnilsOGK/V0DmLb97URrfkuu7n6ZXLCuBfMDxUpE36I3cZo1++32fg7qPnfcvq+g91CIH8ossN31kN4k/4tYTZmOhP1ulEl3YipCHsQl8hUvWSq+3aQfTeBr6fkLC8DJOLpOgr4EBi0Lk/1LrBiTYynLyQxvar0JKhgxA7jbaQKXJnRn7WruFzeda8bZwxKEznth7bHHy47VV5HCRJAVPz/0wy855kcfn8yyE84KxKMr9LAj54NRvhGk/o7x73B7kvn3qj8aVWdZqh3nXPQ5ODw1ycXd08L7+9Se+6u3T57Pns78BjFFfFmMPAAA='],
            'webm:1' => ['H4sICJPpKWgAA3dlYm0udHh0AO1XTW/bOBC951cIOrVA40iOY7u5FWmwu8Bmu9jD7qEoiJE0kohIpEBSTrJF/vsOKcdrUaLbXnooKhiG7Pc4w/ngG+nzWURXrI1CaHV8HX10f9jr8+HOUbgo8JEIyZvx/7ksMGcCWiQwrpfrVTzLaKSoDrRfF8SLLqJ3f9/Q992ft7+cr8Y/OlAmShPfVqdkyZvBBq/qeVfmqXOMHS9QBihQMQqai8oSPyafDp8g3xKTx4Qun/LAC1MTnC63fnZqpF0awjbLucQV7MTagXDKQiM1MXLoDJdCzxSHctWySgEXM2ANmmWsVFSTubUa2q5BBrrD3DAF5MOmIL1O/fgLrrsGnqbU9fXbSQH5IytbG0/81O9Wy6TzGQ3usCH8MvXDrZVsgRoptw5sRMQtjb++5NgUTKoClaVQx1QKteY79JkKSxu474drBrvcrjWqnywS0LAGRWVqpvm/rs8mHa+GrNpMOMLy6mKSNNhVX8EynAgZaEdIL9KZ7tOGjgrrjA1lNSmiA60V176LZDXXwBk3mnWoaCsPbKi7pW99Hj4aBQUYeAn9ajXTClLzfX3GEjIQsIS+MdO0D2ifTTvRIVLxilPuA3Au2xaFCaDNk+L5TI878B4UyHsMoKVUORYBsEawEsJ42wFXQdqO65665gusvEEQDMuSzlBor2AM5DWd+Y7nAYqtdcFM3beZAN6ELAkpWMGxQhO0FNYVBxeoc8VPUlo0rl2CFjqkqRIsmza8sWmDKlSdllqJk/S4o56M8Gf/JEGl51vy9o+bD+9v/7Id/zsd/KvtIr1cLeioRXaaMbFDkcdj24dfR14C83KiYqN5Kbtef8W8/EC06JX7/k0YVJAb0rPoXV9wGd1Y/uvToxAs8zuMwv3Q2Ct82ZiJvO8ZL6K32s5YyWsQAl3vLuchRkWXvXOiKR/TMX+QtIOc+dLIBckUHcsOimII/jL1vflCnlxM9jrV8RnSN8r4+eakjJ+TjiebGRsTfU7f/tTnn/r8w+pzwzMnoCFxdnefBke2U1owIxf2+RhfNNagNosHzNqjUxWLjP3/brQcA+7JckCSuSX04C37zoeHbRyUne6V1Pfwxve8543GwN2eTK9J/2B2d0z/skLEL4+r6XqTppvL7foYpGAyZDqXyulGcrzl2dJ4ZSmpLJu1rUrs5f/57PnsP12aOQFnDgAA'],
            'wmv:1' => ['H4sICLjpKWgAA3dtdi50eHQA7VdNb+M2EL3nVwg6tUCbSLJsxznVmyBo0Xq7SILkECyIsTiyiegLJK0kXeS/d0g5TiyKcXtrgTV8sPTeDIej4Xvyt6OAPqHSEqFU4Vlwb2+Yz7fdL0sRFccnIkQ/7d/Pao4Zq6BEAsPHEtokHKQUdbXa8e4oXf2oggVyAcF8w0UdeOL0c2NDwJA8FFgx2oKoVpZ4H3+9j8zXyza06CmKJ3GfoqBsCmR5qQ0nL3TjYUjQtqw0jaPIWWgNVYWFaWjSg5ZCK9agZF2egY6KSmgBBWuA825LfYZkuaRG7mqITpwKoF0dJmlB+BKUxeMT2ohDURqkZo1WA2V0mEli8x9H9tPPwDdUgagrZnOk8Wyaehi2nfExEQbSUN/een4aJw4Bn7QEDhqYEn8ZWtyvlwvV1Epsl9qf746AOWwK7W51W+bSg9RSrEQFhQfO6rLEype2eJYiG2ivBR9AQv0wMCUWzWuZIfeAawRzIpgoGxDSS2uF2tCwHWBlBULFMM8xGxoFywGtIVsjZ43IPBQzK5zp9aZcViAKX6aKpoULXKH2ZsqgMc/Rl4GjyqT4kFKituPizdAgSZ73sSktCtM2WPmeTkmjJAp4RmkIe/jL7urlLdAjuPEBwW1H/1pvbwXHOpj14xpZ58JKUrgAUX0sx63J8U/k+G5x66lwJ8Sj0XiS8vG0T3sUXK+Jchr1j/IaxWptHs3EgUxuzvyhHf5RgqJWxPBPWEhdKtlKmh654BoUW3baOyianX2AaugoMSt8Vn3PHCsyckXj41DTM6efjXh6taznTZsmkeNaBbZo9Onn2czxKlmXQOOS7US4wFz3E0jM1cA09q1oZCzAOImzHdeR/Nz/qzHFo9OhlRxrchb67kzfnWlH+Y84k/31tQs3D7QEvTecRgbx1WA0Kn1MZvRu9MNqyd7+WCT7AHkNCWjZa8FbCMlrvWn6cFfFztVA5aGL7hnf/Poy+GHOW6hoHoOTYJ5p0WJwbRehIQwubdCP7/MclI+DwhBuz3k4ncSzdLoH7elFOk6SZPIepsYskamslt077Pvtk2EqRx/IYC+vL37/jMjtITEFH3dF93XoAluR4Xld2U5RR26QzGhby+LLL7/+0Y+4W5zcLeYXV+dfEB6uMEeJFGbos3E88bLnLUoav72AaeT4+2c6bSj/zC9fzTKMkzR1s5r93aJU247HZnfmm47cd4bf1O2nK8vqI3Q/MNvohDpNo9G4T/m0IR2RwbZ8Q0ySU2cJLXT3lnRDQx/c2CvnJeNVWDvS+fa6d7pejl6O/gblvHcwgw8AAA=='],
        ];
    }
}
